<?php

declare(strict_types=1);

namespace App\Permit\Application\Result;

use App\Permit\Domain\ZonePlan;

/** Résultat d'une opération de zones : le plan appliqué (changements, zones rattachées après l'opération). */
final readonly class ZoneChangeResult
{
    public function __construct(public ZonePlan $plan)
    {
    }
}
