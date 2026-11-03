<?php

declare(strict_types=1);

namespace App\Event\Application;

/**
 * Niveau de visibilité d'une commune sur les participations d'un événement (DM-084).
 */
enum ParticipantListVisibility: string
{
    /** Étrangère à l'événement : aucune visibilité. */
    case None = 'NONE';
    /** Simplement invitée : sa propre invitation, jamais la liste. */
    case OwnInvitationOnly = 'OWN_INVITATION_ONLY';
    /** Participante (acceptée : ACTIVE, SUSPENDED ou CLOSED) : liste de coordination limitée. */
    case Coordination = 'COORDINATION';
    /** Organisatrice : toutes les participations et leurs informations de gestion. */
    case Full = 'FULL';
}
