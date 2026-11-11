<?php

declare(strict_types=1);

namespace App\Event\Application;

/**
 * Qui lit quoi dans le bilan de clôture (LOT 05.6.2, P5 et P6). `INSTRUCTOR` n'y accède jamais par défaut ; l'accès au bilan ne donne AUCUN accès aux dossiers
 * archivés (05.6.3).
 */
enum ClosureStatisticsAudience
{
    case None;
    /** Commune organisatrice, rôle de gestion : bilan global ; sa propre répartition ; ventilation seulement avec la coordination (`$breakdown`). */
    case Organizer;
    /** Commune participante : sa propre ligne, jamais le global ni les autres communes. */
    case Participant;
}
