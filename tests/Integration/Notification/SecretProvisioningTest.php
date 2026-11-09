<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Notification\Application\KeyRingProvider;
use App\Notification\Application\SecretProvisioner;
use App\Notification\Domain\ProvisionOutcome;
use App\Notification\Domain\SecretDecryptionFailed;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\AuditAction;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\NotificationKit;
use PHPUnit\Framework\Attributes\Group;

/**
 * Création du lien automatique et du secret chiffré (LOT 05.3.2) : atomicité, rejeu, fail-closed sans clé, 7 jours, pertinence à la reprise,
 * rotation, liaison au message, aucun secret dans l'audit. Horloge figée au 12 juin 2026 09:00 UTC (événement du 10 au 20 juin).
 */
#[Group('requires-request-schema')]
final class SecretProvisioningTest extends MunicipalDecisionTestCase
{
    private KeyRingProvider $keys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = NotificationKit::provider(...NotificationKit::newKey('k1'));
    }

    private function managed(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? $user;
    }

    private function approve(User $agent, Municipality $commune, string $requestId): void
    {
        $this->instruction()->approve($this->managed($agent), $commune->id()->toRfc4122(), $requestId, ApprovalDuration::fixed(DurationMode::H24));
    }

    private function submitFor(?string $email = 'lea@exemple.fr'): string
    {
        return $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
            $this->event->id()->toRfc4122(),
            'Dupont',
            'Léa',
            null,
            $email,
            null === $email ? '+33612345678' : null,
            'private_person',
            'home',
            null,
            TravelMode::Vehicle,
            ['QW-123-ER'],
            [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()],
            true,
        ), $this->key())->requestId;
    }

    /** Approuve une demande et retourne l'identifiant de sa ligne PERMIT_ISSUED. */
    private function issuedRow(?string $email = 'lea@exemple.fr'): \Symfony\Component\Uid\Uuid
    {
        $this->approve($this->instructor, $this->organizer, $this->submitFor($email));

        return $this->outboxId('PERMIT_ISSUED');
    }

    private function outboxId(string $type): \Symfony\Component\Uid\Uuid
    {
        return \Symfony\Component\Uid\Uuid::fromBinary((string) $this->em->getConnection()->fetchOne('SELECT id FROM notification_outbox WHERE type = :t ORDER BY id DESC', ['t' => $type]));
    }

    private function provisioner(?KeyRingProvider $keys = null, ?AuditTrail $audit = null): SecretProvisioner
    {
        return NotificationKit::provisioner(self::getContainer(), $keys ?? $this->keys, $this->clock, $audit);
    }

    /** @return array<string, mixed> */
    private function outboxRow(): array
    {
        return $this->em->getConnection()->fetchAssociative('SELECT status, attempts, last_error_code, processed_at FROM notification_outbox WHERE type = \'PERMIT_ISSUED\'') ?: [];
    }

    /** @return array{int, int} liens d'accès, secrets chiffrés */
    private function counts(): array
    {
        return [$this->rows('permit_access_token'), $this->rows('notification_secret')];
    }

    public function testProvisioningCreatesOneSystemLinkAndOneEncryptedSecretMatchingTheLinkHash(): void
    {
        $id = $this->issuedRow();

        self::assertSame(ProvisionOutcome::Provisioned, $this->provisioner()->provision($id));

        self::assertSame([1, 1], $this->counts());
        $token = $this->em->getConnection()->fetchAssociative('SELECT issuer_type, issued_by_user_id, issued_by_municipality_id, expires_at, revoked_at, secret_hash FROM permit_access_token');
        self::assertSame(['SYSTEM', null, null, null], [$token['issuer_type'], $token['issued_by_user_id'], $token['issued_by_municipality_id'], $token['revoked_at']]);
        $secret = NotificationKit::vault(self::getContainer(), $this->keys)->reveal($id);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $secret);
        self::assertSame($token['secret_hash'], hash('sha256', $secret), 'le secret chiffré est bien celui du lien créé');
        $stored = $this->em->getConnection()->fetchAssociative('SELECT key_id, nonce, ciphertext, expires_at, created_at FROM notification_secret');
        self::assertSame('k1', $stored['key_id']);
        self::assertStringNotContainsString($secret, (string) $stored['ciphertext']);
        self::assertSame(172800, strtotime($stored['expires_at'].' UTC') - strtotime($stored['created_at'].' UTC'), '48 heures');
        self::assertNull($this->outboxRow()['last_error_code']);
        self::assertSame('PENDING', $this->outboxRow()['status']);
    }

    public function testReplayingTheProvisioningCreatesNoSecondLinkNorSecret(): void
    {
        $id = $this->issuedRow();
        $this->provisioner()->provision($id);

        self::assertSame(ProvisionOutcome::AlreadyProvisioned, $this->provisioner()->provision($id));
        self::assertSame([1, 1], $this->counts());
        self::assertSame(1, $this->rows('audit_log WHERE action = \'PERMIT_ACCESS_LINK_ISSUED\''));
    }

    public function testAddedCommuneAndNoRecipientMessagesGetNoLink(): void
    {
        $request = $this->submitFor();
        $this->approve($this->instructor, $this->organizer, $request);
        $this->approve($this->partnerInstructor, $this->partner, $request);

        self::assertSame(ProvisionOutcome::Skipped, $this->provisioner()->provision($this->outboxId('AUTHORIZATION_ADDED')));

        $this->sql('DELETE FROM notification_outbox');
        $this->sql('DELETE FROM permit_access_token');
        $noRecipient = \Symfony\Component\Uid\Uuid::fromBinary((string) $this->em->getConnection()->fetchOne('SELECT UNHEX(REPLACE(UUID(), \'-\', \'\'))'));
        $this->sql('INSERT INTO notification_outbox (id, status, attempts, deduplication_key, type, permit_id, occurred_at, available_at, processed_at) SELECT :id, \'NO_RECIPIENT\', 0, \'PERMIT_ISSUED:x\', \'PERMIT_ISSUED\', id, NOW(), NOW(), NOW() FROM permit_permit', ['id' => $noRecipient->toBinary()]);
        self::assertSame(ProvisionOutcome::Skipped, $this->provisioner()->provision($noRecipient));
        self::assertSame([0, 0], $this->counts());
    }

    public function testAnUnknownMessageIsSkipped(): void
    {
        self::assertSame(ProvisionOutcome::Skipped, $this->provisioner()->provision(\Symfony\Component\Uid\Uuid::v7()));
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function unusableRings(): iterable
    {
        yield 'absent' => [null, null];
        yield 'mal formé' => ['k1:pas-du-base64***', 'k1'];
        yield 'clé active inconnue' => ['k1:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'autre'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableRings')]
    public function testWithoutAUsableKeyNoLinkIsCreatedTheMessageWaitsAndNoAttemptIsConsumed(?string $keyring, ?string $active): void
    {
        $id = $this->issuedRow();

        self::assertSame(ProvisionOutcome::CryptoUnavailable, $this->provisioner(NotificationKit::provider($keyring, $active))->provision($id));

        self::assertSame([0, 0], $this->counts());
        $row = $this->outboxRow();
        self::assertSame(['PENDING', '0', 'CRYPTO_UNAVAILABLE', null], [$row['status'], (string) $row['attempts'], $row['last_error_code'], $row['processed_at']]);
        self::assertSame(0, $this->rows('audit_log WHERE action = \'PERMIT_ACCESS_LINK_ISSUED\''));
    }

    public function testApprovalsAreNeverBlockedByAMissingKeyAndTheMessageResumesWhenTheKeyReturns(): void
    {
        // Aucune clé dans l'environnement de test : l'approbation réussit, l'intention est enregistrée.
        $id = $this->issuedRow();
        self::assertSame(1, $this->rows('request_permit_request_decision'));
        self::assertSame(ProvisionOutcome::CryptoUnavailable, $this->provisioner(NotificationKit::provider(null, null))->provision($id));
        $this->clock->sleep(3600);

        self::assertSame(ProvisionOutcome::Provisioned, $this->provisioner()->provision($id));

        self::assertSame([1, 1], $this->counts());
        self::assertNull($this->outboxRow()['last_error_code'], 'anomalie levée à la reprise');
    }

    public function testAfterSevenDaysWithoutAKeyTheMessageFailsDefinitivelyWithoutAnyLink(): void
    {
        $id = $this->issuedRow();
        $this->provisioner(NotificationKit::provider(null, null))->provision($id);
        $this->clock->sleep(7 * 86400 - 1);
        self::assertSame(ProvisionOutcome::CryptoUnavailable, $this->provisioner(NotificationKit::provider(null, null))->provision($id), 'encore en attente à 7 jours moins une seconde');

        $this->clock->sleep(1);
        self::assertSame(ProvisionOutcome::CryptoExpired, $this->provisioner(NotificationKit::provider(null, null))->provision($id));

        $row = $this->outboxRow();
        self::assertSame(['ABANDONED', 'CRYPTO_UNAVAILABLE_EXPIRED'], [$row['status'], $row['last_error_code']]);
        self::assertNotNull($row['processed_at']);
        self::assertSame([0, 0], $this->counts());
    }

    public function testAKeyReturningAfterTheSevenDaysDoesNotResurrectTheMessage(): void
    {
        $id = $this->issuedRow();
        $this->provisioner(NotificationKit::provider(null, null))->provision($id);
        $this->clock->sleep(7 * 86400);

        self::assertSame(ProvisionOutcome::CryptoExpired, $this->provisioner()->provision($id));

        self::assertSame([0, 0], $this->counts());
        self::assertSame('ABANDONED', $this->outboxRow()['status']);
    }

    public function testOnResumeAPermitThatIsNoLongerUsableGetsNoLinkAndNoAnnouncement(): void
    {
        $id = $this->issuedRow();
        $this->provisioner(NotificationKit::provider(null, null))->provision($id);
        $this->sql('UPDATE permit_authorization SET revoked_at = :now', ['now' => '2026-06-12 10:00:00']);

        self::assertSame(ProvisionOutcome::NotRelevant, $this->provisioner()->provision($id));

        $row = $this->outboxRow();
        self::assertSame(['ABANDONED', 'NOT_RELEVANT'], [$row['status'], $row['last_error_code']]);
        self::assertSame([0, 0], $this->counts());
    }

    public function testAnExpiredAccessWindowAlsoMakesTheMessageIrrelevant(): void
    {
        $id = $this->issuedRow();
        $this->clock->sleep(60 * 86400); // bien après la fin de l'événement + 30 jours

        self::assertSame(ProvisionOutcome::NotRelevant, $this->provisioner()->provision($id));
        self::assertSame([0, 0], $this->counts());
    }

    public function testAFailureAfterTheLinkWasWrittenRollsBackTheLinkTheSecretAndTheMessageState(): void
    {
        $id = $this->issuedRow();
        $inner = self::getContainer()->get(AuditTrail::class);
        $failing = new class ($inner) implements AuditTrail {
            public function __construct(private readonly AuditTrail $inner)
            {
            }

            public function record(AuditEvent $event): void
            {
                if (AuditAction::PermitAccessLinkIssued === $event->action) {
                    throw new \RuntimeException('audit indisponible');
                }
                $this->inner->record($event);
            }
        };

        try {
            $this->provisioner(null, $failing)->provision($id);
            self::fail('Le provisionnement doit être annulé.');
        } catch (\RuntimeException $e) {
            self::assertSame('audit indisponible', $e->getMessage());
        }

        self::assertSame([0, 0], $this->counts(), 'jamais de lien sans secret conservé');
        self::assertSame('PENDING', $this->outboxRow()['status']);
        self::assertSame(0, $this->rows('audit_log WHERE action = \'PERMIT_ACCESS_LINK_ISSUED\''));
    }

    public function testTheAuditNamesTheSystemAndNeverContainsTheSecretNorItsHash(): void
    {
        $id = $this->issuedRow();
        $this->provisioner()->provision($id);
        $secret = NotificationKit::vault(self::getContainer(), $this->keys)->reveal($id);
        $hash = (string) $this->em->getConnection()->fetchOne('SELECT secret_hash FROM permit_access_token');

        $audit = $this->em->getConnection()->fetchAssociative('SELECT * FROM audit_log WHERE action = \'PERMIT_ACCESS_LINK_ISSUED\'');
        self::assertIsArray($audit);
        $dump = json_encode($this->em->getConnection()->fetchAllAssociative('SELECT * FROM audit_log'), \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($secret, $dump);
        self::assertStringNotContainsString($hash, $dump);
        self::assertSame('SYSTEM', json_decode((string) $audit['new_value'], true)['issuer']);
        self::assertNull($audit['actor_id'], 'acteur système : aucun compte');
    }

    public function testTheSecretIsBoundToItsMessageSoARewrittenRowCannotBeDecrypted(): void
    {
        $id = $this->issuedRow();
        $this->provisioner()->provision($id);
        $vault = NotificationKit::vault(self::getContainer(), $this->keys);
        self::assertNotSame('', $vault->reveal($id));

        $this->sql('UPDATE notification_outbox SET permit_id = :other', ['other' => random_bytes(16)]);

        $this->expectException(SecretDecryptionFailed::class);
        NotificationKit::vault(self::getContainer(), $this->keys)->reveal($id);
    }

    public function testRevealingFailsWithoutKeyOrWithoutSecretAndNeverQuotesAnything(): void
    {
        $id = $this->issuedRow();
        foreach ([[$this->keys, 'sans secret'], [NotificationKit::provider(null, null), 'sans clé']] as [$keys, $label]) {
            try {
                NotificationKit::vault(self::getContainer(), $keys)->reveal($id);
                self::fail($label);
            } catch (SecretDecryptionFailed $e) {
                self::assertSame('Déchiffrement impossible.', $e->getMessage());
            }
            $this->provisioner()->provision($id);
        }
    }

    public function testRotationKeepsOldSecretsReadableAndNewOnesUseTheActiveKey(): void
    {
        $old = NotificationKit::newKey('2025a');
        $new = NotificationKit::newKey('2026b');
        $first = $this->issuedRow();
        $this->provisioner(NotificationKit::provider(...$old))->provision($first);
        $rotated = NotificationKit::provider($new[0].','.$old[0], '2026b');

        self::assertNotSame('', NotificationKit::vault(self::getContainer(), $rotated)->reveal($first));
        $second = $this->issuedRow();
        $this->provisioner($rotated)->provision($second);

        self::assertSame(['2025a', '2026b'], $this->em->getConnection()->fetchFirstColumn('SELECT key_id FROM notification_secret ORDER BY created_at, key_id'));
        $this->expectException(SecretDecryptionFailed::class);
        NotificationKit::vault(self::getContainer(), NotificationKit::provider($new[0], '2026b'))->reveal($first);
    }
}
