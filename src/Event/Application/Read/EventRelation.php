<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

/** Lien de la commune qui consulte avec l'événement. */
enum EventRelation: string
{
    case Organizer = 'organizer';
    /** Participation acceptée (ACTIVE, SUSPENDED ou CLOSED). */
    case Participant = 'participant';
    /** Simplement invitée : ne voit que les informations nécessaires à sa décision. */
    case Invited = 'invited';
}
