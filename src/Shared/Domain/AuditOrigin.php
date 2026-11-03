<?php

declare(strict_types=1);

namespace App\Shared\Domain;

enum AuditOrigin: string
{
    case User = 'USER';
    case Console = 'CONSOLE';
    case System = 'SYSTEM';
    /** Opérateur CWS agissant en assistance (DM-061) : l'opérateur réel est tracé à part. */
    case Support = 'SUPPORT';
}
