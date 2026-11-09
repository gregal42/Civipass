<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/**
 * État OPÉRATIONNEL d'un droit (une zone d'une autorisation) à un instant donné : calculé, jamais stocké (DM-112, DT-047).
 * Précédence des causes (de la plus à la moins prioritaire) : REVOKED, COMMUNE_INACTIVE, EVENT_CLOSED, EVENT_NOT_ACTIVE,
 * PARTICIPATION_INACTIVE, ZONE_SUSPENDED, puis l'état temporel NOT_YET_VALID / OUTSIDE_PERIOD / EXPIRED / VALID.
 */
enum RightState: string
{
    /** Le droit est ouvert à l'instant évalué. */
    case Valid = 'VALID';
    /** Aucune période d'accès n'a encore commencé (la fenêtre ou la première période est à venir). */
    case NotYetValid = 'NOT_YET_VALID';
    /** Toutes les périodes d'accès sont passées, ou la fenêtre d'autorisation est terminée. */
    case Expired = 'EXPIRED';
    /** Dans la fenêtre, entre deux périodes d'accès de la zone (ou aucune période dans la fenêtre). */
    case OutsidePeriod = 'OUTSIDE_PERIOD';
    case ZoneSuspended = 'ZONE_SUSPENDED';
    case ParticipationInactive = 'PARTICIPATION_INACTIVE';
    /** Commune administrativement SUSPENDED ou INACTIVE (DM-035). */
    case CommuneInactive = 'COMMUNE_INACTIVE';
    case EventNotActive = 'EVENT_NOT_ACTIVE';
    case EventClosed = 'EVENT_CLOSED';
    case Revoked = 'REVOKED';
    /** La zone interrogée ne figure dans aucune autorisation du laissez-passer. */
    case ZoneNotAuthorized = 'ZONE_NOT_AUTHORIZED';
}
