<?php

declare(strict_types=1);

namespace App\Notification\Domain;

use Symfony\Component\Uid\Uuid;

interface NotificationSecretRepository
{
    public function add(NotificationSecret $secret): void;

    public function findByOutbox(Uuid $outboxId): ?NotificationSecret;

    public function remove(NotificationSecret $secret): void;

    /**
     * Lignes dont le secret doit disparaître : message en état définitif, ou secret échu.
     *
     * @return list<Uuid> identifiants d'outbox
     */
    public function findPurgeable(\DateTimeImmutable $now, int $limit): array;
}
