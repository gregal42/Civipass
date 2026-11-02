<?php

declare(strict_types=1);

namespace App\Shared\Domain;

enum AuditSubjectType: string
{
    case User = 'USER';
    case Municipality = 'MUNICIPALITY';
    case MunicipalityMembership = 'MUNICIPALITY_MEMBERSHIP';
}
