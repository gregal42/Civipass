<?php

declare(strict_types=1);

namespace App\Permit\Application\Result;

use App\Permit\Domain\VehiclePlan;

/** Résultat d'une opération de plaques : le plan appliqué (changements, nombre de plaques actives après l'opération). */
final readonly class VehicleChangeResult
{
    public function __construct(public VehiclePlan $plan)
    {
    }
}
