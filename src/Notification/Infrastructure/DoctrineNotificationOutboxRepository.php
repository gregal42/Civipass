<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure;

use App\Notification\Domain\NotificationOutbox;
use App\Notification\Domain\NotificationOutboxRepository;
use App\Notification\Domain\ChangeCategory;
use App\Notification\Domain\NotificationType;
use App\Notification\Domain\OutboxErrorCode;
use App\Notification\Domain\OutboxStatistics;
use App\Notification\Domain\OutboxStatus;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(NotificationOutboxRepository::class)]
final readonly class DoctrineNotificationOutboxRepository implements NotificationOutboxRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(Uuid $id): ?NotificationOutbox
    {
        return $this->em->find(NotificationOutbox::class, $id);
    }

    public function findFresh(Uuid $id): ?NotificationOutbox
    {
        $query = $this->em->createQueryBuilder()
            ->select('o')
            ->from(NotificationOutbox::class, 'o')
            ->where('o.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $row = $query->getOneOrNullResult();

        return $row instanceof NotificationOutbox ? $row : null;
    }

    public function findDispatchable(\DateTimeImmutable $now, int $limit): array
    {
        $rows = $this->em->getConnection()->fetchFirstColumn(
            'SELECT id FROM notification_outbox WHERE (status = :pending AND available_at <= :now) OR (status = :reserved AND leased_until <= :now) ORDER BY CASE type WHEN \'AUTHORIZATION_REVOKED\' THEN 0 WHEN \'PERMIT_ISSUED\' THEN 1 ELSE 2 END, available_at, id LIMIT '.max(1, $limit),
            ['pending' => OutboxStatus::Pending->value, 'reserved' => OutboxStatus::Reserved->value, 'now' => self::utc($now)],
        );

        return array_map(static fn (mixed $id): Uuid => Uuid::fromBinary((string) $id), $rows);
    }

    public function statistics(\DateTimeImmutable $now, \DateInterval $failedWindow, \DateInterval $leaseGrace): OutboxStatistics
    {
        $c = $this->em->getConnection();
        $byStatus = array_map('intval', $c->fetchAllKeyValue('SELECT status, COUNT(*) FROM notification_outbox GROUP BY status'));
        $byError = array_map('intval', $c->fetchAllKeyValue('SELECT last_error_code, COUNT(*) FROM notification_outbox WHERE last_error_code IS NOT NULL GROUP BY last_error_code'));
        $waiting = [OutboxStatus::Pending->value, OutboxStatus::Reserved->value];
        $oldestWaiting = $c->fetchOne('SELECT MIN(occurred_at) FROM notification_outbox WHERE status IN (:s)', ['s' => $waiting], ['s' => \Doctrine\DBAL\ArrayParameterType::STRING]);
        $oldestDue = $c->fetchOne('SELECT MIN(available_at) FROM notification_outbox WHERE status = :p AND available_at <= :now', ['p' => OutboxStatus::Pending->value, 'now' => self::utc($now)]);
        $age = static fn (mixed $at): ?int => \is_string($at) ? max(0, $now->getTimestamp() - (new \DateTimeImmutable($at, new \DateTimeZone('UTC')))->getTimestamp()) : null;

        return new OutboxStatistics(
            $byStatus,
            $byError,
            $age($oldestWaiting),
            $age($oldestDue),
            (int) $c->fetchOne('SELECT COUNT(*) FROM notification_outbox WHERE status = :r AND leased_until <= :t', ['r' => OutboxStatus::Reserved->value, 't' => self::utc($now->sub($leaseGrace))]),
            (int) $c->fetchOne('SELECT COUNT(*) FROM notification_outbox WHERE status = :a AND processed_at >= :t', ['a' => OutboxStatus::Abandoned->value, 't' => self::utc($now->sub($failedWindow))]),
            (int) $c->fetchOne('SELECT COUNT(*) FROM notification_secret WHERE expires_at <= :now', ['now' => self::utc($now)]),
            (int) $c->fetchOne('SELECT COUNT(*) FROM notification_outbox WHERE status = :p AND last_error_code = :code', ['p' => OutboxStatus::Pending->value, 'code' => OutboxErrorCode::CryptoUnavailable->value]),
        );
    }

    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function lock(Uuid $id): ?NotificationOutbox
    {
        $query = $this->em->createQueryBuilder()
            ->select('o')
            ->from(NotificationOutbox::class, 'o')
            ->where('o.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $query->setHint(Query::HINT_REFRESH, true);
        $row = $query->getOneOrNullResult();

        return $row instanceof NotificationOutbox ? $row : null;
    }

    public function findAwaitingSecret(int $limit): array
    {
        $rows = $this->em->getConnection()->fetchFirstColumn(
            'SELECT o.id FROM notification_outbox o WHERE o.type = :type AND o.status = :status AND NOT EXISTS (SELECT 1 FROM notification_secret s WHERE s.outbox_id = o.id) ORDER BY o.available_at, o.id LIMIT '.max(1, $limit),
            ['type' => NotificationType::PermitIssued->value, 'status' => OutboxStatus::Pending->value],
        );

        return array_map(static fn (mixed $id): Uuid => Uuid::fromBinary((string) $id), $rows);
    }

    public function addIfAbsent(NotificationOutbox $outbox): bool
    {
        $key = $outbox->deduplicationKey();
        // Lignes déjà persistées dans l'unité de travail mais pas encore écrites : invisibles d'une requête.
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $scheduled) {
            if ($scheduled instanceof NotificationOutbox && $scheduled->deduplicationKey() === $key) {
                return false;
            }
        }
        if ($this->existsByKey($key)) {
            return false;
        }
        $this->em->persist($outbox);

        return true;
    }

    public function findPurgeableBefore(\DateTimeImmutable $before, bool $firstMessagesSent, int $limit): array
    {
        $final = array_map(static fn (OutboxStatus $s): string => $s->value, array_values(array_filter(OutboxStatus::cases(), static fn (OutboxStatus $s): bool => $s->isFinal())));
        $rows = $this->em->getConnection()->fetchFirstColumn(
            'SELECT id FROM notification_outbox WHERE status IN (:final) AND processed_at IS NOT NULL AND processed_at < :before AND ((type = :first AND status = :sent) = :onlyFirst) ORDER BY processed_at, id LIMIT '.max(1, $limit),
            ['final' => $final, 'before' => self::utc($before), 'first' => NotificationType::PermitIssued->value, 'sent' => OutboxStatus::Sent->value, 'onlyFirst' => $firstMessagesSent ? 1 : 0],
            ['final' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        return array_map(static fn (mixed $id): Uuid => Uuid::fromBinary((string) $id), $rows);
    }

    public function remove(NotificationOutbox $outbox): void
    {
        $this->em->remove($outbox);
    }

    public function existsByKey(string $deduplicationKey): bool
    {
        return null !== $this->em->getRepository(NotificationOutbox::class)->findOneBy(['deduplicationKey' => $deduplicationKey]);
    }

    public function findFirstMessagesShared(Uuid $permitId): array
    {
        return $this->forPermit($permitId, [NotificationType::PermitIssued], LockMode::PESSIMISTIC_READ);
    }

    public function findSentShared(Uuid $permitId): array
    {
        return array_values(array_filter(
            $this->forPermit($permitId, NotificationType::cases(), LockMode::PESSIMISTIC_READ),
            static fn (NotificationOutbox $o): bool => OutboxStatus::Sent === $o->status(),
        ));
    }

    public function lockPendingOrdinary(Uuid $permitId): array
    {
        $ordinary = array_values(array_filter(NotificationType::cases(), static fn (NotificationType $t): bool => ChangeCategory::Ordinary === $t->category()));

        return array_values(array_filter(
            $this->forPermit($permitId, $ordinary, LockMode::PESSIMISTIC_WRITE),
            static fn (NotificationOutbox $o): bool => OutboxStatus::Pending === $o->status(),
        ));
    }

    public function activeOfType(Uuid $permitId, NotificationType $type, ?Uuid $authorizationId = null): array
    {
        return array_values(array_filter(
            $this->forPermit($permitId, [$type], null),
            static fn (NotificationOutbox $row): bool => \in_array($row->status(), [OutboxStatus::Pending, OutboxStatus::Reserved], true) && (null === $authorizationId || ($row->authorizationId()?->equals($authorizationId) ?? false)),
        ));
    }

    public function pendingRevisionIds(Uuid $permitId, NotificationType $type, ?Uuid $authorizationId = null): array
    {
        $ids = [];
        foreach ($this->forPermit($permitId, [$type], null) as $row) {
            if (OutboxStatus::Pending === $row->status() && null !== $row->revisionId() && (null === $authorizationId || ($row->authorizationId()?->equals($authorizationId) ?? false))) {
                $ids[] = $row->revisionId()->toRfc4122();
            }
        }

        return $ids;
    }

    public function findManualRequestsShared(Uuid $permitId): array
    {
        return array_values(array_filter($this->findFirstMessagesShared($permitId), static fn (NotificationOutbox $o): bool => $o->isManualRequest()));
    }

    public function hasActiveFirstMessage(Uuid $permitId): bool
    {
        foreach ($this->findFirstMessagesShared($permitId) as $first) {
            if (\in_array($first->status(), [OutboxStatus::Pending, OutboxStatus::Reserved], true)) {
                return true;
            }
        }

        return false;
    }

    public function findForPermit(Uuid $permitId): array
    {
        return $this->forPermit($permitId, NotificationType::cases(), null);
    }

    /**
     * @param list<NotificationType> $types
     *
     * @return list<NotificationOutbox>
     */
    private function forPermit(Uuid $permitId, array $types, ?LockMode $lock): array
    {
        $query = $this->em->createQueryBuilder()
            ->select('o')
            ->from(NotificationOutbox::class, 'o')
            ->where('o.permitId = :permit')
            ->andWhere('o.type IN (:types)')
            ->setParameter('permit', $permitId, 'uuid')
            ->setParameter('types', array_map(static fn (NotificationType $t): string => $t->value, $types))
            ->orderBy('o.occurredAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        if (null !== $lock) {
            $query->setLockMode($lock);
        }

        /** @var list<NotificationOutbox> $rows */
        $rows = $query->getResult();

        return $rows;
    }
}
