<?php

declare(strict_types=1);

namespace App\Event\Domain\Validity;

/**
 * Mode de configuration de la validité d'une zone (DM-011) : ce n'est pas un statut métier.
 */
enum ValidityMode: string
{
    case Unconfigured = 'UNCONFIGURED';
    case EventDuration = 'EVENT_DURATION';
    case SpecificPeriods = 'SPECIFIC_PERIODS';
}
