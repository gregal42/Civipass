<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

/** Situation d'une commune trouvée vis-à-vis de l'événement : seule `Available` peut être invitée. */
enum InvitationCandidateState: string
{
    case Available = 'available';
    /** Invitation en attente. */
    case Invited = 'invited';
    /** Participation acceptée (active ou suspendue). */
    case Participating = 'participating';
    /** Participation clôturée : jamais réinvitée (DM-089). */
    case Closed = 'closed';
}
