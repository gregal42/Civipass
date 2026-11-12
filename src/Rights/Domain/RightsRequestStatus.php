<?php

declare(strict_types=1);

namespace App\Rights\Domain;

enum RightsRequestStatus: string
{
    case Received = 'RECEIVED';
    case IdentityVerified = 'IDENTITY_VERIFIED';
    case Decided = 'DECIDED';
    case Closed = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Reçue',
            self::IdentityVerified => 'Identité attestée',
            self::Decided => 'Décidée',
            self::Closed => 'Clôturée',
        };
    }
}
