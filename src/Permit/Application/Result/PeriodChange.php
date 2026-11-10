<?php

declare(strict_types=1);

namespace App\Permit\Application\Result;

use App\Permit\Domain\PeriodPlan;

/** Résultat d'une opération de période : le plan appliqué (période courante résultante, reprise, fin immédiate). */
final readonly class PeriodChange
{
    public function __construct(public PeriodPlan $plan, public bool $unusual = false)
    {
    }
}
