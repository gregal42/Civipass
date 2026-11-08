<?php

declare(strict_types=1);

namespace App\Request\Application\Read;

use App\Request\Domain\DecisionOutcome;
use App\Request\Domain\DecisionPath;
use App\Request\Domain\DurationMode;

/**
 * Décision de la commune consultante sur SA portion (lecture seule, extraite de PermitRequestDecision) : jamais la décision d'une autre commune.
 * `validUntil` nul avec le mode « jusqu'à clôture » : la fin dépend de l'état de l'événement.
 */
final readonly class DecisionView
{
    public function __construct(
        public DecisionOutcome $outcome,
        public DecisionPath $path,
        public \DateTimeImmutable $decidedAt,
        public string $decidedBy,
        public ?DurationMode $durationMode,
        public ?\DateTimeImmutable $validFrom,
        public ?\DateTimeImmutable $validUntil,
        public ?string $refusalReasonLabel,
        public ?string $refusalComment,
    ) {
    }
}
