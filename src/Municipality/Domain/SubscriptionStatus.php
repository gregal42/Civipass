<?php

declare(strict_types=1);

namespace App\Municipality\Domain;

enum SubscriptionStatus: string
{
    case Trial = 'TRIAL';
    case Active = 'ACTIVE';
    case Expired = 'EXPIRED';
    case Cancelled = 'CANCELLED';
}
