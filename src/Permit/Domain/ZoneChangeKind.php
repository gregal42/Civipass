<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/** Nature du changement d'une zone dans une autorisation (LOT 05.5.3). */
enum ZoneChangeKind: string
{
    case Removed = 'REMOVED';
    case Reinstated = 'REINSTATED';
    case Added = 'ADDED';
    case LabelCorrected = 'LABEL_CORRECTED';

    public function revisionType(): RevisionType
    {
        return match ($this) {
            self::Removed => RevisionType::ZoneRemoved,
            self::Reinstated => RevisionType::ZoneReinstated,
            self::Added => RevisionType::ZoneAdded,
            self::LabelCorrected => RevisionType::ZoneLabelCorrected,
        };
    }
}
