<?php

declare(strict_types=1);

namespace App\Notification\Application;

enum OrderingDecision: string
{
    /** Le message peut suivre son cours. */
    case Proceed = 'PROCEED';
    /** Un message de mise à disposition est encore en attente : l'extension attend, rien n'est supprimé. */
    case Held = 'HELD';
    /** Le message a été supprimé (redondant ou premier message non remis). */
    case Suppressed = 'SUPPRESSED';
    /** Plus à traiter (déjà pris, déjà définitif). */
    case Skipped = 'SKIPPED';
}
