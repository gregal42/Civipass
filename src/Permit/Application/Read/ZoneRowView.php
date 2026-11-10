<?php

declare(strict_types=1);

namespace App\Permit\Application\Read;

use App\Permit\Domain\RightState;

/** Zone d'une autorisation pour l'écran de la commune (LOT 05.5.3) : libellé copié, libellé courant, état des droits, retirée (avec sa date) ou rattachée, libellé à corriger. */
final readonly class ZoneRowView
{
    public function __construct(
        public string $id,
        public string $label,
        public string $currentLabel,
        public ?RightState $state,
        public ?\DateTimeImmutable $removedAt,
    ) {
    }

    public function isRemoved(): bool
    {
        return null !== $this->removedAt;
    }

    public function isLabelOutdated(): bool
    {
        return $this->label !== $this->currentLabel;
    }
}
