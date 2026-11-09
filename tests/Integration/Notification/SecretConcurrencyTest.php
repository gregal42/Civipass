<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Identity\Domain\User;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\NotificationKit;
use App\Tests\Support\ParallelPhp;
use PHPUnit\Framework\Attributes\Group;

/**
 * Concurrence réelle (processus PHP sur MySQL) du provisionnement et de la purge (LOT 05.3.2) : un message ne produit jamais deux liens ni deux
 * secrets, une purge simultanée ne révoque ni n'audite deux fois.
 */
#[Group('requires-request-schema')]
final class SecretConcurrencyTest extends MunicipalDecisionTestCase
{
    private string $keyring;
    private string $active;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->keyring, $this->active] = NotificationKit::newKey('k1');
        putenv('NOTIFICATION_KEYRING_TEST='.$this->keyring);
        putenv('NOTIFICATION_ACTIVE_KEY_ID_TEST='.$this->active);
    }

    protected function tearDown(): void
    {
        putenv('NOTIFICATION_KEYRING_TEST');
        putenv('NOTIFICATION_ACTIVE_KEY_ID_TEST');
        putenv('NOTIFICATION_TEST_CLOCK_OFFSET');
        parent::tearDown();
    }

    private function approved(): string
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

        return bin2hex((string) $this->em->getConnection()->fetchOne('SELECT id FROM notification_outbox ORDER BY occurred_at DESC, id DESC'));
    }

    /**
     * @param list<array{status: int, stdout: string, stderr: string}> $raw
     *
     * @return list<array<string, mixed>>
     */
    private function decode(array $raw): array
    {
        return array_map(function (array $r): array {
            $decoded = json_decode($r['stdout'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded, $r['stderr']);
            self::assertTrue($decoded['ok'], json_encode($decoded).' '.$r['stderr']);

            return $decoded;
        }, $raw);
    }

    public function testSixSimultaneousProvisioningsOfTheSameMessageCreateOneLinkAndOneSecret(): void
    {
        $id = $this->approved();

        $results = $this->decode(ParallelPhp::run('notification-process.php', array_fill(0, 6, ['provision', substr($id, 0, 8).'-'.substr($id, 8, 4).'-'.substr($id, 12, 4).'-'.substr($id, 16, 4).'-'.substr($id, 20)])));

        $outcomes = array_count_values(array_column($results, 'outcome'));
        self::assertEqualsCanonicalizing(['PROVISIONED' => 1, 'ALREADY_PROVISIONED' => 5], $outcomes);
        self::assertSame([1, 1], [$this->rows('permit_access_token'), $this->rows('notification_secret')]);
        self::assertSame(1, $this->rows('audit_log WHERE action = \'PERMIT_ACCESS_LINK_ISSUED\''));
    }

    public function testProvisioningSeveralMessagesAtTheSameTimeNeverDeadlocks(): void
    {
        $ids = [];
        for ($i = 0; $i < 4; ++$i) {
            $ids[] = $this->approved();
        }
        $arguments = [];
        foreach ($ids as $id) {
            $uuid = substr($id, 0, 8).'-'.substr($id, 8, 4).'-'.substr($id, 12, 4).'-'.substr($id, 16, 4).'-'.substr($id, 20);
            $arguments[] = ['provision', $uuid];
            $arguments[] = ['provision', $uuid];
        }

        $results = $this->decode(ParallelPhp::run('notification-process.php', $arguments));

        self::assertEqualsCanonicalizing(['PROVISIONED' => 4, 'ALREADY_PROVISIONED' => 4], array_count_values(array_column($results, 'outcome')));
        self::assertSame([4, 4], [$this->rows('permit_access_token'), $this->rows('notification_secret')]);
    }

    public function testTwoSimultaneousPurgesRevokeAndAuditOnce(): void
    {
        $id = $this->approved();
        $this->decode(ParallelPhp::run('notification-process.php', [['provision', substr($id, 0, 8).'-'.substr($id, 8, 4).'-'.substr($id, 12, 4).'-'.substr($id, 16, 4).'-'.substr($id, 20)]]));
        $this->sql('UPDATE notification_outbox SET status = \'ABANDONED\', processed_at = NOW()');

        $results = $this->decode(ParallelPhp::run('notification-process.php', [['purge'], ['purge'], ['purge']]));

        self::assertSame(1, array_sum(array_column(array_column($results, 'report'), 'revoked')));
        self::assertSame(1, array_sum(array_column(array_column($results, 'report'), 'purged')));
        self::assertSame(0, $this->rows('notification_secret'));
        self::assertSame(1, $this->rows('audit_log WHERE action = \'PERMIT_ACCESS_LINK_REVOKED\''));
    }

    public function testAProvisioningRacingAPurgeNeverLeavesALinkWithoutAKeptSecretOrAnUnrevokedOrphan(): void
    {
        $id = $this->approved();
        $uuid = substr($id, 0, 8).'-'.substr($id, 8, 4).'-'.substr($id, 12, 4).'-'.substr($id, 16, 4).'-'.substr($id, 20);
        $this->sql('UPDATE notification_outbox SET status = \'ABANDONED\', processed_at = NOW()');

        $this->decode(ParallelPhp::run('notification-process.php', [['provision', $uuid], ['purge'], ['provision', $uuid]]));

        self::assertSame([0, 0], [$this->rows('permit_access_token'), $this->rows('notification_secret')], 'message définitif : aucun lien automatique n\'est créé');
    }
}
