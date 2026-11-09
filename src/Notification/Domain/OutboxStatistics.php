<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/** Photographie de l'outbox pour la supervision : compteurs et âges, jamais de donnée personnelle ni de secret. */
final readonly class OutboxStatistics
{
    /**
     * @param array<string, int> $byStatus             messages par état
     * @param array<string, int> $byErrorCode          messages en attente ou abandonnés par code d'anomalie
     * @param int|null           $oldestWaitingSeconds âge du plus ancien message en attente (PENDING ou RESERVED), depuis sa création
     * @param int|null           $oldestDueSeconds     retard du plus ancien message PENDING déjà échu (distributeur en retard ou arrêté)
     */
    public function __construct(
        public array $byStatus,
        public array $byErrorCode,
        public ?int $oldestWaitingSeconds,
        public ?int $oldestDueSeconds,
        public int $stuckReservations,
        public int $failedRecently,
        public int $overdueSecrets,
        public int $cryptoWaiting,
    ) {
    }
}
