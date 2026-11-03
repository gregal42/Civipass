<?php

declare(strict_types=1);

namespace App\Event\Domain\Time;

use App\Municipality\Domain\InseeCode;

/**
 * Référentiel communal des fuseaux non encore fourni : aucune exception connue.
 */
final readonly class EmptyCommuneTimezoneOverrides implements CommuneTimezoneOverrides
{
    public function find(InseeCode $code): ?EventTimezone
    {
        return null;
    }
}
