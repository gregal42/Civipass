<?php

declare(strict_types=1);

namespace App\Identity\Application;

enum TwoFactorOutcome: string
{
    case Accepted = 'ACCEPTED';
    case AcceptedBackupCode = 'ACCEPTED_BACKUP_CODE';
    case Rejected = 'REJECTED';

    public function isAccepted(): bool
    {
        return self::Rejected !== $this;
    }
}
