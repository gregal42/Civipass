<?php

declare(strict_types=1);

namespace App\Event\Domain\Time;

use App\Shared\Domain\DomainRuleViolation;

final class AmbiguousLocalTime extends DomainRuleViolation
{
    public function __construct(public readonly LocalDate $date, public readonly LocalTime $time, public readonly EventTimezone $timezone)
    {
        parent::__construct(sprintf(
            'L\'horaire %s du %s se produit deux fois (changement d\'heure, fuseau %s). Choisissez la première ou la deuxième occurrence.',
            $time,
            $date,
            $timezone,
        ));
    }
}
