<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Identity\Domain\User;
use App\Notification\Application\KeyRingProvider;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\NotificationKit;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Purge des secrets et révocation des liens automatiques non transmis (LOT 05.3.2, D13). Horloge figée au 12 juin 2026 09:00 UTC.
 */
#[Group('requires-request-schema')]
final class SecretPurgeTest extends MunicipalDecisionTestCase
{
    private KeyRingProvider $keys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = NotificationKit::provider(...NotificationKit::newKey('k1'));
    }

    /** Approuve une demande, crée le lien automatique et son secret ; retourne l'identifiant de la ligne d'outbox. */
    private function provisioned(): Uuid
    {
        $user = $this->em->find(User::class, $this->instructor->id()) ?? $this->instructor;
        $request = $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
            $this->event->id()->toRfc4122(),
            'Dupont',
            'Léa',
            null,
            'lea@exemple.fr',
            null,
            'private_person',
            'home',
            null,
            TravelMode::Vehicle,
            ['QW-123-ER'],
            [$this->organizerZone->id()->toRfc4122()],
            true,
        ), $this->key())->requestId;
        $this->instruction()->approve($user, $this->organizer->id()->toRfc4122(), $request, ApprovalDuration::fixed(DurationMode::H24));
        $id = Uuid::fromBinary((string) $this->em->getConnection()->fetchOne('SELECT id FROM notification_outbox ORDER BY occurred_at DESC, id DESC'));
        NotificationKit::provisioner(self::getContainer(), $this->keys, $this->clock)->provision($id);

        return $id;
    }

    /** @return array{purged: int, revoked: int, expired: int} */
    private function purge(): array
    {
        return NotificationKit::purger(self::getContainer(), $this->clock)->purge();
    }

    private function outboxStatus(Uuid $id): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT status FROM notification_outbox WHERE id = :id', ['id' => $id->toBinary()]);
    }

    private function forceStatus(Uuid $id, string $status): void
    {
        $this->sql('UPDATE notification_outbox SET status = :s, processed_at = \'2026-06-12 09:00:00\' WHERE id = :id', ['s' => $status, 'id' => $id->toBinary()]);
    }

    public function testASentMessageLosesItsSecretButKeepsItsLinkActive(): void
    {
        $id = $this->provisioned();
        $this->forceStatus($id, 'SENT');

        self::assertSame(['purged' => 1, 'revoked' => 0, 'expired' => 0], $this->purge());

        self::assertSame(0, $this->rows('notification_secret'));
        self::assertNull($this->em->getConnection()->fetchOne('SELECT revoked_at FROM permit_access_token'), 'le bénéficiaire possède ce lien');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('neverDelivered')]
    public function testAMessageNeverDeliveredLosesItsSecretAndItsAutomaticLinkIsRevokedBySystem(string $status): void
    {
        $id = $this->provisioned();
        $this->forceStatus($id, $status);

        self::assertSame(['purged' => 1, 'revoked' => 1, 'expired' => 0], $this->purge());

        self::assertSame(0, $this->rows('notification_secret'));
        $token = $this->em->getConnection()->fetchAssociative('SELECT revoked_at, revoked_by_user_id FROM permit_access_token');
        self::assertNotNull($token['revoked_at']);
        self::assertNull($token['revoked_by_user_id'], 'révocation par le système');
        self::assertSame(1, $this->rows('audit_log WHERE action = \'PERMIT_ACCESS_LINK_REVOKED\''));
    }

    /** @return iterable<string, array{string}> */
    public static function neverDelivered(): iterable
    {
        yield 'abandonné' => ['ABANDONED'];
        yield 'sans destinataire' => ['NO_RECIPIENT'];
    }

    public function testASecretOlderThan48HoursIsDeletedItsMessageFailsAndItsLinkIsRevoked(): void
    {
        $id = $this->provisioned();
        $this->clock->sleep(48 * 3600 - 1);
        self::assertSame(['purged' => 0, 'revoked' => 0, 'expired' => 0], $this->purge(), 'rien avant 48 heures');
        self::assertSame(1, $this->rows('notification_secret'));

        $this->clock->sleep(1);
        self::assertSame(['purged' => 1, 'revoked' => 1, 'expired' => 1], $this->purge());

        self::assertSame('ABANDONED', $this->outboxStatus($id));
        self::assertSame('SECRET_EXPIRED', $this->em->getConnection()->fetchOne('SELECT last_error_code FROM notification_outbox'));
        self::assertSame(0, $this->rows('notification_secret'));
        self::assertNotNull($this->em->getConnection()->fetchOne('SELECT revoked_at FROM permit_access_token'));
    }

    public function testAnActiveMessageWithAFreshSecretIsLeftAlone(): void
    {
        $id = $this->provisioned();

        self::assertSame(['purged' => 0, 'revoked' => 0, 'expired' => 0], $this->purge());

        self::assertSame(['PENDING', 1], [$this->outboxStatus($id), $this->rows('notification_secret')]);
    }

    public function testThePurgeIsIdempotentAndNeverRevokesAnAgentLink(): void
    {
        $id = $this->provisioned();
        $this->forceStatus($id, 'ABANDONED');
        // Un lien émis par un agent sur le même laissez-passer ne doit jamais être touché.
        $this->sql('INSERT INTO permit_access_token (id, secret_hash, created_at, issued_by_user_id, issued_by_municipality_id, permit_id, issuer_type) SELECT UNHEX(REPLACE(UUID(), \'-\', \'\')), REPEAT(\'a\', 64), NOW(), :u, :m, permit_id, \'AGENT\' FROM permit_access_token LIMIT 1', ['u' => $this->instructor->id()->toBinary(), 'm' => $this->organizer->id()->toBinary()]);

        self::assertSame(['purged' => 1, 'revoked' => 1, 'expired' => 0], $this->purge());
        self::assertSame(['purged' => 0, 'revoked' => 0, 'expired' => 0], $this->purge());

        self::assertSame(1, $this->rows('permit_access_token WHERE revoked_at IS NULL'));
        self::assertSame('AGENT', $this->em->getConnection()->fetchOne('SELECT issuer_type FROM permit_access_token WHERE revoked_at IS NULL'));
        self::assertSame(1, $this->rows('audit_log WHERE action = \'PERMIT_ACCESS_LINK_REVOKED\''));
    }

    public function testPurgedDataLeavesNoTraceOfTheSecretAnywhere(): void
    {
        $id = $this->provisioned();
        $secret = NotificationKit::vault(self::getContainer(), $this->keys)->reveal($id);
        $this->forceStatus($id, 'SENT');
        $this->purge();

        $connection = $this->em->getConnection();
        $dump = json_encode([$connection->fetchAllAssociative('SELECT * FROM notification_outbox'), $connection->fetchAllAssociative('SELECT * FROM notification_secret'), $connection->fetchAllAssociative('SELECT * FROM audit_log')], \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($secret, $dump);
    }
}
