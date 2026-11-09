<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * Droit d'une zone au sein d'une autorisation, à l'instant évalué. `effectiveFrom` / `effectiveUntil` : la période d'accès en cours
 * (VALID). `nextStart` : le début de la prochaine période (NOT_YET_VALID, OUTSIDE_PERIOD). Instants en UTC ; le fuseau d'affichage est celui de l'événement.
 */
final readonly class ZoneRight
{
    public function __construct(
        public Uuid $zoneId,
        public string $label,
        public RightState $state,
        public ?\DateTimeImmutable $effectiveFrom = null,
        public ?\DateTimeImmutable $effectiveUntil = null,
        public ?\DateTimeImmutable $nextStart = null,
    ) {
    }

    public function isValid(): bool
    {
        return RightState::Valid === $this->state;
    }
}
