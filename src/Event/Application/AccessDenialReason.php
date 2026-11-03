<?php

declare(strict_types=1);

namespace App\Event\Application;

/**
 * Motif d'un refus. NoAccess ne distingue pas « inexistant » de « non autorisé » : aucune énumération possible.
 */
enum AccessDenialReason: string
{
    /** Aucun lien effectif avec la ressource (membership absent ou inactif, commune sans accès, ressource étrangère). */
    case NoAccess = 'NO_ACCESS';
    /** Commune SUSPENDED ou INACTIVE : lecture seule ou rien (D16). */
    case MunicipalityNotWritable = 'MUNICIPALITY_NOT_WRITABLE';
    case RoleNotAllowed = 'ROLE_NOT_ALLOWED';
    /** Commune sans droit d'utilisation valide (création d'événement seulement). */
    case NoUsageRight = 'NO_USAGE_RIGHT';
    case EventClosed = 'EVENT_CLOSED';
    case InvalidEventState = 'INVALID_EVENT_STATE';
    case InvalidParticipationState = 'INVALID_PARTICIPATION_STATE';
    /** La participation organisatrice se clôture avec l'événement (A6.3). */
    case OrganizerParticipationBoundToEvent = 'ORGANIZER_PARTICIPATION_BOUND_TO_EVENT';
    /** Zones non modifiables : participation INVITED ou CLOSED (DM-058, DM-066). */
    case ParticipationNotEditable = 'PARTICIPATION_NOT_EDITABLE';
    case ZoneDeleted = 'ZONE_DELETED';
    case SlotDeleted = 'SLOT_DELETED';
}
