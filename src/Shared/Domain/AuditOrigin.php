<?php

declare(strict_types=1);

namespace App\Shared\Domain;

enum AuditOrigin: string
{
    case User = 'USER';
    case Console = 'CONSOLE';
    case System = 'SYSTEM';

    /** Action d'une personne sans compte (dépôt public d'une demande) : aucun acteur identifié. */
    case Public = 'PUBLIC';
    /** Opérateur CWS agissant en assistance (DM-061) : l'opérateur réel est tracé à part. */
    case Support = 'SUPPORT';
}
