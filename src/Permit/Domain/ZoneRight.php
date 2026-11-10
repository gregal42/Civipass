<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Event\Domain\Validity\UtcInterval;
use Symfony\Component\Uid\Uuid;

/**
 * Droit d'une zone au sein d'une autorisation, à l'instant évalué. `effectiveFrom` / `effectiveUntil` : la période d'accès en cours
 * (VALID). `nextStart` : le début de la prochaine période (NOT_YET_VALID, OUTSIDE_PERIOD). `periods` : périodes prévues (vérification
 * publique seulement, vide sinon). `removedAt` : date du retrait (état REMOVED). Instants en UTC ; le fuseau d'affichage est celui de l'événement.
 */
final readonly class ZoneRight
{
    /**
     * @param list<UtcInterval> $periods
     */
    public function __construct(
        public Uuid $zoneId,
        public string $label,
        public RightState $state,
        public ?\DateTimeImmutable $effectiveFrom = null,
        public ?\DateTimeImmutable $effectiveUntil = null,
        public ?\DateTimeImmutable $nextStart = null,
        public array $periods = [],
        public ?\DateTimeImmutable $removedAt = null,
    ) {
    }

    /**
     * Même droit avec ses périodes PRÉVUES (tous les intervalles, passés, en cours et à venir). Renseigné uniquement par l'évaluation de
     * vérification publique : un état non valide garde ainsi ce qui était prévu, sans le présenter comme actif.
     *
     * @param list<UtcInterval> $periods
     */
    public function withPeriods(array $periods): self
    {
        return new self($this->zoneId, $this->label, $this->state, $this->effectiveFrom, $this->effectiveUntil, $this->nextStart, $periods, $this->removedAt);
    }

    public function isValid(): bool
    {
        return RightState::Valid === $this->state;
    }
}
