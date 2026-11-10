<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Request\Domain\PlateFormat;
use Symfony\Component\Uid\Uuid;

/** État d'une ligne de plaque du laissez-passer (LOT 05.5.4) tel que la règle le reçoit : identifiant, plaque normalisée et son format, position, retirée ou non. */
final readonly class VehicleRowState
{
    public function __construct(public Uuid $vehicleId, public string $registration, public PlateFormat $format, public int $position, public bool $removed)
    {
    }
}
