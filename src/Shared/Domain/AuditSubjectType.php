<?php

declare(strict_types=1);

namespace App\Shared\Domain;

enum AuditSubjectType: string
{
    case User = 'USER';
    case Municipality = 'MUNICIPALITY';
    case MunicipalityMembership = 'MUNICIPALITY_MEMBERSHIP';
    case MunicipalityCommercialAccess = 'MUNICIPALITY_COMMERCIAL_ACCESS';
    case Event = 'EVENT';
    case EventParticipation = 'EVENT_PARTICIPATION';
    case EventZone = 'EVENT_ZONE';
    case EventZoneValidity = 'EVENT_ZONE_VALIDITY';
    case EventType = 'EVENT_TYPE';
}
