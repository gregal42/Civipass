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
    /** Participante (acceptée : ACTIVE, SUSPENDED ou CLOSED) ou instructeur de l'organisatrice, événement ouvert : liste de coordination limitée. */
    case Coordination = 'COORDINATION';
    /** Après clôture (U6) : historique des communes ayant effectivement participé (identifiant, nom, indicateur d'organisatrice). */
    case History = 'HISTORY';
    /** Organisatrice, rôles de gestion (administrateur, gestionnaire) : toutes les participations et leurs informations de gestion. */
    case Full = 'FULL';
}
