<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Request\Domain\PlateFormat;
use Symfony\Component\Uid\Uuid;

/** Un changement de plaque planifié. `vehicleId` est nul pour une plaque NOUVELLE (la ligne n'existe pas encore) ; `position` : celle de la ligne (nouvelle : la suivante). */
final readonly class VehicleChange
{
    public function __construct(
        public VehicleChangeKind $kind,
        public ?Uuid $vehicleId,
        public string $registration,
        public PlateFormat $format,
        public int $position,
        /** Numéro du remplacement (0, 1, …) qui relie la plaque sortante et la plaque entrante ; nul hors remplacement. */
        public ?int $pair = null,
    ) {
    }
}
