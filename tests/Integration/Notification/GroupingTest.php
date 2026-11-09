<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Notification\Domain\NotificationOutbox;
use App\Notification\Domain\NotificationOutboxRepository;
use App\Notification\Domain\NotificationType;
use App\Permit\Application\NotificationIntentRecorder;
use App\Permit\Domain\NotificationIntent;
use App\Permit\Domain\NotificationKind;
use App\Shared\Application\TransactionRunner;
use App\Tests\Support\EventSchemaTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Regroupement déterministe des changements ordinaires d'un même laissez-passer (LOT 05.3.4, D5) : une échéance commune, indépendante de l'ordre
 * d'arrivée, plafonnée à 60 minutes depuis le premier changement ; un groupe est fermé par l'envoi ; la première mise à disposition et la
 * révocation ne sont jamais regroupées.
 */
#[Group('requires-event-schema')]
final class GroupingTest extends EventSchemaTestCase
{
    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-06-12 '.$time, new \DateTimeZone('UTC'));
    }

    private function record(Uuid $permit, string $time, NotificationKind $kind = NotificationKind::AuthorizationAdded): Uuid
    {
        $authorization = Uuid::v7();
        self::getContainer()->get(TransactionRunner::class)->run(function () use ($permit, $authorization, $time, $kind): void {
            self::getContainer()->get(NotificationIntentRecorder::class)->record(new NotificationIntent($kind, $permit, $authorization, $this->at($time), true));
        });

        return $authorization;
    }

    /** @return list<string> */
    private function schedule(Uuid $permit, string $type = 'AUTHORIZATION_ADDED'): array
    {
        return $this->em->getConnection()->fetchFirstColumn('SELECT available_at FROM notification_outbox WHERE permit_id = :p AND type = :t ORDER BY occurred_at', ['p' => $permit->toBinary(), 't' => $type]);
    }

    public function testChangesCloseTogetherShareOneDueDateTenMinutesAfterTheLastOne(): void
    {
        $permit = Uuid::v7();
        $this->record($permit, '09:00:00');
        self::assertSame(['2026-06-12 09:10:00'], $this->schedule($permit));

        $this->record($permit, '09:04:00');
        $this->record($permit, '09:08:00');

        self::assertSame(array_fill(0, 3, '2026-06-12 09:18:00'), $this->schedule($permit));
    }

    public function testTheGroupNeverWaitsMoreThanSixtyMinutesAfterItsFirstChange(): void
    {
        $permit = Uuid::v7();
        for ($minute = 0; $minute <= 72; $minute += 9) {
            $this->record($permit, sprintf('%02d:%02d:00', 9 + intdiv($minute, 60), $minute % 60));
        }

        self::assertSame(['2026-06-12 10:00:00'], array_values(array_unique($this->schedule($permit))), 'plafond de 60 minutes depuis le premier changement');
    }

    public function testTheDueDateDoesNotDependOnTheArrivalOrder(): void
    {
        $times = ['09:00:00', '09:03:00', '09:20:00', '09:07:00', '09:41:00'];
        $first = Uuid::v7();
        foreach ($times as $t) {
            $this->record($first, $t);
        }
        $second = Uuid::v7();
        foreach (array_reverse($times) as $t) {
            $this->record($second, $t);
        }

        self::assertSame($this->schedule($first), $this->schedule($second));
        self::assertSame(array_fill(0, 5, '2026-06-12 09:51:00'), $this->schedule($first));
    }

    public function testASentMessageClosesTheGroupAndTheNextChangeStartsANewOne(): void
    {
        $permit = Uuid::v7();
        $this->record($permit, '09:00:00');
        $this->em->getConnection()->executeStatement('UPDATE notification_outbox SET status = \'SENT\', processed_at = :t', ['t' => '2026-06-12 09:12:00']);

        $this->record($permit, '09:30:00');

        self::assertSame(['2026-06-12 09:10:00', '2026-06-12 09:40:00'], $this->schedule($permit), 'le message déjà envoyé n\'est pas replanifié ; le nouveau groupe repart de zéro');
    }

    public function testGroupsOfDifferentPermitsAreIndependent(): void
    {
        $a = Uuid::v7();
        $b = Uuid::v7();
        $this->record($a, '09:00:00');
        $this->record($b, '09:30:00');
        $this->record($a, '09:05:00');

        self::assertSame(['2026-06-12 09:15:00', '2026-06-12 09:15:00'], $this->schedule($a));
        self::assertSame(['2026-06-12 09:40:00'], $this->schedule($b));
    }

    public function testTheFirstIssuanceIsNeverGroupedNorDelayed(): void
    {
        $permit = Uuid::v7();
        $this->record($permit, '09:00:00');
        $this->record($permit, '09:01:00', NotificationKind::PermitIssued);

        self::assertSame(['2026-06-12 09:01:00'], $this->schedule($permit, 'PERMIT_ISSUED'));
        self::assertSame(['2026-06-12 09:10:00'], $this->schedule($permit), 'le changement ordinaire garde son échéance');
    }

    public function testAReplayedIntentChangesNothing(): void
    {
        $permit = Uuid::v7();
        $authorization = $this->record($permit, '09:00:00');
        $this->record($permit, '09:04:00');
        $before = $this->schedule($permit);

        self::getContainer()->get(TransactionRunner::class)->run(function () use ($permit, $authorization): void {
            self::getContainer()->get(NotificationIntentRecorder::class)->record(new NotificationIntent(NotificationKind::AuthorizationAdded, $permit, $authorization, $this->at('09:00:00'), true));
        });

        self::assertSame($before, $this->schedule($permit));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM notification_outbox'));
    }

    public function testARevocationIsDueImmediatelyAndDispatchedBeforeEverythingElse(): void
    {
        $permit = Uuid::v7();
        $this->record($permit, '09:00:00');
        $revocation = NotificationOutbox::record('REVOKED:test', NotificationType::AuthorizationRevoked, $permit, Uuid::v7(), $this->at('09:05:00'), true);
        self::getContainer()->get(TransactionRunner::class)->run(function () use ($revocation): void {
            self::getContainer()->get(NotificationOutboxRepository::class)->addIfAbsent($revocation);
        });
        $this->em->getConnection()->executeStatement('INSERT INTO notification_outbox (id, status, attempts, deduplication_key, type, permit_id, occurred_at, available_at) VALUES (UNHEX(REPLACE(UUID(), \'-\', \'\')), \'PENDING\', 0, \'PERMIT_ISSUED:z\', \'PERMIT_ISSUED\', :p, :t, :t)', ['p' => $permit->toBinary(), 't' => '2026-06-12 08:00:00']);

        $ids = self::getContainer()->get(NotificationOutboxRepository::class)->findDispatchable($this->at('10:00:00'), 10);
        $types = array_map(fn (Uuid $id): string => (string) $this->em->getConnection()->fetchOne('SELECT type FROM notification_outbox WHERE id = :id', ['id' => $id->toBinary()]), $ids);

        self::assertSame(['AUTHORIZATION_REVOKED', 'PERMIT_ISSUED', 'AUTHORIZATION_ADDED'], $types, 'révocation prioritaire, puis mise à disposition, puis changements ordinaires');
        self::assertSame(['2026-06-12 09:05:00'], $this->schedule($permit, 'AUTHORIZATION_REVOKED'), 'aucune attente de regroupement');
    }
}
