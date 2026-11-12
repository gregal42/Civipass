<?php

declare(strict_types=1);

namespace App\Rights\Domain;

enum RightsDecision: string
{
    case Granted = 'GRANTED';
    case Partial = 'PARTIAL';
    case Refused = 'REFUSED';

    public function label(): string
    {
        return match ($this) {
            self::Granted => 'Accordée',
            self::Partial => 'Accordée en partie',
            self::Refused => 'Refusée',
        };
    }

    public function isGrant(): bool
    {
        return self::Refused !== $this;
    }
}
