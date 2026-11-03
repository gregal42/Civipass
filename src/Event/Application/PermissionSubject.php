<?php

declare(strict_types=1);

namespace App\Event\Application;

/**
 * Ressource sur laquelle porte une permission : détermine la chaîne de rattachement à vérifier.
 */
enum PermissionSubject
{
    /** Commune cible d'une création d'événement. */
    case NewEvent;
    /** Event → commune organisatrice (et communes participantes pour la lecture). */
    case Event;
    /** EventParticipation → Municipality (et commune organisatrice pour la lecture). */
    case Participation;
    /** EventZone → EventParticipation → Municipality. */
    case Zone;
    /** EventZoneValidity → EventZone → EventParticipation → Municipality. */
    case ZoneValiditySlot;
}
