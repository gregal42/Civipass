<?php

declare(strict_types=1);

namespace App\Shared\Domain;

enum AuditOrigin: string
{
    case User = 'USER';
    case Console = 'CONSOLE';
    case System = 'SYSTEM';
}
