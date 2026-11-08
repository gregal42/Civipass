<?php

declare(strict_types=1);

namespace App\Request\Application\Result;

use App\Request\Domain\DecisionOutcome;

/** Résultat d'une décision : la nature, l'instant et, pour une approbation, la période globale (fin nulle : jusqu'à clôture). */
final readonly class MunicipalDecisionResult
{
    public function __construct(
        public string $requestId,
        public string $reference,
        public DecisionOutcome $outcome,
        public \DateTimeImmutable $decidedAt,
        public ?\DateTimeImmutable $validFrom,
        public ?\DateTimeImmutable $validUntil,
    ) {
    }
}
