<?php

declare(strict_types=1);

namespace App\Permit\Application\Read;

use App\Permit\Domain\RightState;

/**
 * Zone telle que la voit le contrôle public : libellé et état courant, jamais un identifiant. `periods` : périodes PRÉVUES (tous les
 * intervalles, passés, en cours et à venir) ; elles ne disent pas que le droit est actif, c'est `state` qui le dit. Vide pour une zone retirée,
 * dont `removedAt` donne la date.
 */
final readonly class PublicZone
{
    /**
     * @param list<PublicPeriod> $periods
     */
    public function __construct(
        public string $label,
        public RightState $state,
        public array $periods,
        public ?\DateTimeImmutable $removedAt,
    ) {
    }

    public function isRemoved(): bool
    {
        return RightState::Removed === $this->state;
    }
}
