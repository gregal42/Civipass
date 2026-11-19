<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

enum AdministratorValidationStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
}
