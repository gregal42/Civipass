<?php

declare(strict_types=1);

namespace App\Permit\Application\Result;

enum PublicVerificationOutcome: string
{
    /** Jeton actif : le laissez-passer est lisible. */
    case Found = 'FOUND';
    /** Jeton retiré (QR remplacé, DM-113) : aucune donnée. */
    case Replaced = 'REPLACED';
    /** Événement définitivement clos : statut neutre « Événement terminé », AUCUNE donnée du laissez-passer (LOT 05.5.5). */
    case EventClosed = 'EVENT_CLOSED';
    /** Jeton bien formé mais inconnu. La réponse publique est la même que pour un jeton mal formé ; seul le compte des échecs les distingue. */
    case Unknown = 'UNKNOWN';
    /** Jeton mal formé : rejeté sans aucun accès à la base. */
    case Malformed = 'MALFORMED';
}
