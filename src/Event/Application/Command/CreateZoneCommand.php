<?php

declare(strict_types=1);

namespace App\Event\Application\Command;

/**
 * Entrée de EventZoneService::create. `type` : valeur de ZoneType ; « Commune entière » exige une confirmation explicite
 * du périmètre (DM-051). Aucune géométrie ni cartographie en V1.
 */
final readonly class CreateZoneCommand
{
    public function __construct(
        public string $label,
        public string $type,
        public ?string $description = null,
        public bool $wholeMunicipalityConfirmed = false,
    ) {
    }
}
