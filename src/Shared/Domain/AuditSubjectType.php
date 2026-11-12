<?php

declare(strict_types=1);

namespace App\Shared\Domain;

enum AuditSubjectType: string
{
    case User = 'USER';
    case Municipality = 'MUNICIPALITY';
    case MunicipalityMembership = 'MUNICIPALITY_MEMBERSHIP';
    case MunicipalityCommercialAccess = 'MUNICIPALITY_COMMERCIAL_ACCESS';
    case NationalCommuneReferential = 'NATIONAL_COMMUNE_REFERENTIAL';
    case InvitationLink = 'INVITATION_LINK';
    case Event = 'EVENT';
    case EventParticipation = 'EVENT_PARTICIPATION';
    case EventZone = 'EVENT_ZONE';
    case EventZoneValidity = 'EVENT_ZONE_VALIDITY';
    case EventType = 'EVENT_TYPE';
    case PermitRequest = 'PERMIT_REQUEST';
    case Permit = 'PERMIT';
    case ArchiveDesignation = 'ARCHIVE_DESIGNATION';
    case StatisticsCoordination = 'STATISTICS_COORDINATION';
    case LegalHold = 'LEGAL_HOLD';
}
