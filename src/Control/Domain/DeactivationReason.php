<?php

declare(strict_types=1);

namespace App\Control\Domain;

/** Motif codé d'une désactivation (aucun texte libre : aucune donnée personnelle ne peut s'y glisser). */
enum DeactivationReason: string
{
    case Lost = 'LOST';
    case Compromised = 'COMPROMISED';
    case EndOfUse = 'END_OF_USE';
    case Rejected = 'REJECTED';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Lost => 'Perdu ou volé',
            self::Compromised => 'Compromis',
            self::EndOfUse => 'Fin d’utilisation',
            self::Rejected => 'Refusé',
            self::Other => 'Autre motif',
        };
    }
}
