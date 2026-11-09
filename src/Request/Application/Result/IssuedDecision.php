<?php

declare(strict_types=1);

namespace App\Request\Application\Result;

use App\Request\Domain\DecisionOutcome;
use App\Request\Domain\DurationMode;
use App\Request\Domain\PermitRequestDecision;

/** Décision enregistrée par une délivrance directe (ou retrouvée au rejeu de la même tentative) : jamais recalculée. */
final readonly class IssuedDecision
{
    public function __construct(
        public DecisionOutcome $outcome,
        public ?DurationMode $durationMode,
        public \DateTimeImmutable $decidedAt,
        public ?\DateTimeImmutable $validFrom,
        public ?\DateTimeImmutable $validUntil,
    ) {
    }

    public static function of(PermitRequestDecision $decision): self
    {
        return new self($decision->outcome(), $decision->durationMode(), $decision->decidedAt(), $decision->validFrom(), $decision->validUntil());
    }
}
