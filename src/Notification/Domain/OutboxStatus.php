<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/** États d'une ligne d'outbox (DT-049, D8). `Sent`, `Abandoned`, `NoRecipient` et `Suppressed` sont définitifs. */
enum OutboxStatus: string
{
    case Pending = 'PENDING';
    case Reserved = 'RESERVED';
    case Sent = 'SENT';
    case Abandoned = 'ABANDONED';
    case NoRecipient = 'NO_RECIPIENT';
    /** Volontairement non envoyé (redondant, inexact, ou premier message non remis) : le motif est dans `last_error_code`. */
    case Suppressed = 'SUPPRESSED';

    public function isFinal(): bool
    {
        return match ($this) {
            self::Sent, self::Abandoned, self::NoRecipient, self::Suppressed => true,
            self::Pending, self::Reserved => false,
        };
    }
}
