<?php

declare(strict_types=1);

namespace App\Rights\Domain;

/** Nature de la demande de droits (LOT 05.6.6). */
enum RightsRequestKind: string
{
    case Access = 'ACCESS';
    case Rectification = 'RECTIFICATION';
    case Erasure = 'ERASURE';
    case ObjectionLimitation = 'OBJECTION_LIMITATION';

    public function label(): string
    {
        return match ($this) {
            self::Access => 'Accès aux données',
            self::Rectification => 'Rectification',
            self::Erasure => 'Effacement',
            self::ObjectionLimitation => 'Opposition ou limitation',
        };
    }
}
