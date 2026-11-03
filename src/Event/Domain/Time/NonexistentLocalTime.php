<?php

declare(strict_types=1);

namespace App\Event\Domain\Time;

use App\Shared\Domain\DomainRuleViolation;

final class NonexistentLocalTime extends DomainRuleViolation
{
    public function __construct(public readonly LocalDate $date, public readonly LocalTime $time, public readonly EventTimezone $timezone)
    {
        parent::__construct(sprintf(
            'L\'horaire %s du %s n\'existe pas (changement d\'heure, fuseau %s). Choisissez un horaire valide.',
            $time,
            $date,
            $timezone,
        ));
    }
}
