<?php

declare(strict_types=1);

namespace App\Event\Domain\Validity;

enum ValidityKind: string
{
    case Continuous = 'CONTINUOUS';
    case Punctual = 'PUNCTUAL';
    case Recurring = 'RECURRING';
}
