<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Identity\Domain\User;
use App\Notification\Application\KeyRingProvider;
use App\Permit\Application\PermitBackfill;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\NotificationKit;
use App\Tests\Support\PermitIssuerFactory;
use App\Tests\Support\RecordingMailer;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Purge des métadonnées de l'outbox à 90 jours (LOT 05.3.5, D13) : seulement les messages DÉFINITIFS, jamais un message actif ni un secret
 * encore présent, jamais un lien, idempotence préservée (un rejeu ne recrée rien), ordonnancement préservé tant que le laissez-passer sert.
 * Horloge figée au 12 juin 2026 09:00 UTC ; les anciennes lignes sont fabriquées en SQL.
 */
#[Group('requires-request-schema')]
final class OutboxPurgeTest extends MunicipalDecisionTestCase
{
    private KeyRingProvider $keys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = NotificationKit::provider(...NotificationKit::newKey('k1'));
    }

    private function approveOrganizer(): string
    {
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
            [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()],
            true,
        ), $this->key())->requestId;
        $this->instruction()->approve($this->em->find(User::class, $this->instructor->id()) ?? $this->instructor, $this->organizer->id()->toRfc4122(), $request, ApprovalDuration::fixed(DurationMode::H72));

        return $request;
    }

    /** @return array{purged: int, retained: int, skipped: int} */
    private function purge(int $days = 90): array
    {
        return NotificationKit::outboxPurger(self::getContainer(), $this->clock)->purge($days);
    }

    private function age(string $where, int $days, int $extraHours = 0): void
    {
        $at = $this->clock->now()->modify(sprintf('-%d days -%d hours', $days, $extraHours))->format('Y-m-d H:i:s');
        $this->sql('UPDATE notification_outbox SET processed_at = :t, occurred_at = :t, available_at = :t WHERE '.$where, ['t' => $at]);
    }

    private function insert(string $status, string $type, string $keySuffix, ?string $processedDaysAgo, ?Uuid $permit = null): Uuid
    {
        $id = Uuid::v7();
        $this->sql('INSERT INTO notification_outbox (id, status, attempts, deduplication_key, type, permit_id, authorization_id, occurred_at, available_at, processed_at) VALUES (:id, :s, 1, :k, :t, :p, :a, :o, :o, :done)', [
            'id' => $id->toBinary(), 's' => $status, 'k' => $type.':'.$keySuffix, 't' => $type, 'p' => ($permit ?? Uuid::v7())->toBinary(), 'a' => Uuid::v7()->toBinary(),
            'o' => $this->clock->now()->modify('-200 days')->format('Y-m-d H:i:s'),
            'done' => null === $processedDaysAgo ? null : $this->clock->now()->modify('-'.$processedDaysAgo)->format('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    private function exists(Uuid $id): bool
    {
        return (bool) $this->em->getConnection()->fetchOne('SELECT 1 FROM notification_outbox WHERE id = :id', ['id' => $id->toBinary()]);
    }

    public function testOnlyDefinitiveMessagesOlderThanTheRetentionAreDeleted(): void
    {
        $old = ['SENT' => $this->insert('SENT', 'AUTHORIZATION_ADDED', 'a', '91 days'), 'ABANDONED' => $this->insert('ABANDONED', 'AUTHORIZATION_ADDED', 'b', '120 days'), 'NO_RECIPIENT' => $this->insert('NO_RECIPIENT', 'PERMIT_ISSUED', 'c', '95 days'), 'SUPPRESSED' => $this->insert('SUPPRESSED', 'AUTHORIZATION_ADDED', 'd', '100 days')];
        $recent = $this->insert('SENT', 'AUTHORIZATION_ADDED', 'e', '89 days 23 hours');
        $pending = $this->insert('PENDING', 'AUTHORIZATION_ADDED', 'f', null);
        $reserved = $this->insert('RESERVED', 'AUTHORIZATION_ADDED', 'g', null);
        $this->sql('UPDATE notification_outbox SET available_at = :old', ['old' => $this->clock->now()->modify('-300 days')->format('Y-m-d H:i:s')]);

        self::assertSame(['purged' => 4, 'retained' => 0, 'skipped' => 0], $this->purge());

        foreach ($old as $state => $id) {
            self::assertFalse($this->exists($id), $state.' de plus de 90 jours : supprimé');
        }
        self::assertTrue($this->exists($recent), 'moins de 90 jours : conservé');
        self::assertTrue($this->exists($pending), 'jamais un message en attente, même très ancien');
        self::assertTrue($this->exists($reserved), 'jamais un message réservé');
    }

    public function testThePurgeIsIdempotentAndTheRetentionIsConfigurable(): void
    {
        $this->insert('SENT', 'AUTHORIZATION_ADDED', 'a', '40 days');

        self::assertSame(['purged' => 0, 'retained' => 0, 'skipped' => 0], $this->purge());
        self::assertSame(['purged' => 1, 'retained' => 0, 'skipped' => 0], $this->purge(30));
        self::assertSame(['purged' => 0, 'retained' => 0, 'skipped' => 0], $this->purge(30));
    }

    public function testAMessageWhoseSecretStillExistsIsNeverPurgedAndNoLinkIsEverTouched(): void
    {
        $request = $this->approveOrganizer();
        $id = Uuid::fromBinary((string) $this->em->getConnection()->fetchOne('SELECT id FROM notification_outbox'));
        NotificationKit::provisioner(self::getContainer(), $this->keys, $this->clock)->provision($id);
        $this->sql('UPDATE notification_outbox SET status = \'ABANDONED\', last_error_code = \'SMTP_PERMANENT\'');
        $this->age('1 = 1', 120);

        self::assertSame(['purged' => 0, 'retained' => 0, 'skipped' => 1], $this->purge(), 'secret encore présent : la purge des secrets passe d\'abord');
        self::assertTrue($this->exists($id));

        $this->sql('DELETE FROM notification_secret');
        self::assertSame(['purged' => 1, 'retained' => 0, 'skipped' => 0], $this->purge());
        self::assertSame([1, 0], [$this->rows('permit_access_token'), $this->rows('permit_access_token WHERE revoked_at IS NOT NULL')], 'la purge ne révoque jamais un lien : elle ne le touche pas');
        self::assertNotSame('', $request);
    }

    public function testASentFirstMessageIsKeptWhileThePermitIsUsableThenPurgedAndOrderingStaysCorrect(): void
    {
        $this->approveOrganizer();
        NotificationKit::dispatcher(self::getContainer(), $this->keys, $this->clock, new RecordingMailer($this->em->getConnection()))->run();
        self::assertSame('SENT', $this->em->getConnection()->fetchOne('SELECT status FROM notification_outbox'));
        $this->age('1 = 1', 0, 24 * 100);

        self::assertSame(['purged' => 0, 'retained' => 1, 'skipped' => 0], $this->purge(), 'le laissez-passer sert encore : la preuve de la première remise est conservée');
        self::assertSame(1, $this->rows('notification_outbox'));

        $this->sql('UPDATE permit_authorization SET revoked_at = :now', ['now' => $this->clock->now()->format('Y-m-d H:i:s')]);
        self::assertSame(['purged' => 1, 'retained' => 0, 'skipped' => 0], $this->purge(), 'plus utilisable : purgé');
    }

    public function testReplayingTheApprovalAfterThePurgeRecordsNoNewNotificationNorDoesTheBackfill(): void
    {
        $request = $this->approveOrganizer();
        $decisionId = $this->em->getConnection()->fetchOne('SELECT id FROM request_permit_request_decision');
        $this->sql('UPDATE notification_outbox SET status = \'ABANDONED\', last_error_code = \'SMTP_PERMANENT\'');
        $this->age('1 = 1', 120);
        self::assertSame(['purged' => 1, 'retained' => 0, 'skipped' => 0], $this->purge());
        self::assertSame(0, $this->rows('notification_outbox'));

        // Rejeu du port d'approbation (la décision existe déjà) : l'autorisation existe, rien n'est ni créé ni notifié.
        $this->service(\App\Shared\Application\TransactionRunner::class)->run(function () use ($decisionId): void {
            $decision = $this->em->find(\App\Request\Domain\PermitRequestDecision::class, Uuid::fromBinary((string) $decisionId)) ?? self::fail('Décision absente.');
            PermitIssuerFactory::create(self::getContainer())->partApproved($decision);
        });
        self::getContainer()->get(PermitBackfill::class)->run(false);

        self::assertSame(0, $this->rows('notification_outbox'), 'la déduplication ne dépend pas de la ligne purgée : aucune notification n\'est recréée');
        self::assertSame([1, 1], [$this->rows('permit_permit'), $this->rows('permit_authorization')]);
        self::assertNotSame('', $request);
    }

    public function testNothingPersonalSurvivesInThePurgeOutputAndTheCommandIsRegistered(): void
    {
        $this->insert('SENT', 'AUTHORIZATION_ADDED', 'a', '120 days');
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \App\Notification\UI\Console\PurgeOutboxCommand(NotificationKit::outboxPurger(self::getContainer(), $this->clock)));

        self::assertSame(0, $tester->execute(['--retention-days' => '90']));

        self::assertStringContainsString('Messages supprimés', $tester->getDisplay());
        self::assertStringNotContainsString('@', $tester->getDisplay());
        self::assertSame(0, $this->rows('notification_outbox'));
    }
}
