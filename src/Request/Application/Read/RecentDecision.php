<?php

declare(strict_types=1);

namespace App\Request\Application\Read;

use App\Request\Domain\DecisionOutcome;

/** Décision récente de LA commune consultante (accueil, LOT 08.1) : jamais une décision d'une autre commune. */
final readonly class RecentDecision
{
    public function __construct(
        public string $requestId,
        public string $reference,
        public string $lastName,
        public string $firstName,
        public DecisionOutcome $outcome,
        public \DateTimeImmutable $decidedAt,
        public string $eventName,
    ) {
    }
}
