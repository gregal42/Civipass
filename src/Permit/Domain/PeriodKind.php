<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/** Nature du changement de période d'une autorisation (LOT 05.5.2). */
enum PeriodKind: string
{
    case Extended = 'EXTENDED';
    case Resumed = 'RESUMED';
    case Shortened = 'SHORTENED';

    public function revisionType(): RevisionType
    {
        return match ($this) {
            self::Extended => RevisionType::PeriodExtended,
            self::Resumed => RevisionType::PeriodResumed,
            self::Shortened => RevisionType::PeriodShortened,
        };
    }
}
