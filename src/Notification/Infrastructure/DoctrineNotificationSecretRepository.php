<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure;

use App\Notification\Domain\NotificationSecret;
use App\Notification\Domain\NotificationSecretRepository;
use App\Notification\Domain\OutboxStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(NotificationSecretRepository::class)]
final readonly class DoctrineNotificationSecretRepository implements NotificationSecretRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function add(NotificationSecret $secret): void
    {
        $this->em->persist($secret);
    }

    public function findByOutbox(Uuid $outboxId): ?NotificationSecret
    {
        return $this->em->getRepository(NotificationSecret::class)->findOneBy(['outboxId' => $outboxId]);
    }

    public function remove(NotificationSecret $secret): void
    {
        $this->em->remove($secret);
    }

    public function findPurgeable(\DateTimeImmutable $now, int $limit): array
    {
        $final = array_map(static fn (OutboxStatus $s): string => $s->value, array_values(array_filter(OutboxStatus::cases(), static fn (OutboxStatus $s): bool => $s->isFinal())));
        $rows = $this->em->getConnection()->fetchFirstColumn(
            'SELECT s.outbox_id FROM notification_secret s JOIN notification_outbox o ON o.id = s.outbox_id WHERE s.expires_at <= :now OR o.status IN (:final) ORDER BY s.expires_at, s.id LIMIT '.max(1, $limit),
            ['now' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'final' => $final],
            ['final' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        return array_map(static fn (mixed $id): Uuid => Uuid::fromBinary((string) $id), $rows);
    }
}
