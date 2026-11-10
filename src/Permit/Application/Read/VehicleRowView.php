<?php

declare(strict_types=1);

namespace App\Permit\Application\Read;

/** Plaque d'un laissez-passer pour l'écran d'une commune habilitée (LOT 05.5.4) : identifiant, plaque affichée, retirée (avec sa date) ou active. Ne sort jamais de cet écran. */
final readonly class VehicleRowView
{
    public function __construct(public string $id, public string $plate, public ?\DateTimeImmutable $removedAt)
    {
    }

    public function isRemoved(): bool
    {
        return null !== $this->removedAt;
    }
}
