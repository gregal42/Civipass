<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/** Motifs d'une RÉINTÉGRATION de zone (DM-119 §8, validé le 10 novembre 2026 pour le LOT 05.5.3) : obligatoire. Jamais public, jamais notifié. */
enum ZoneReinstatementReason: string
{
    case RemovedInError = 'REMOVED_IN_ERROR';
    case NeedReturned = 'NEED_RETURNED';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::RemovedInError => 'Retirée par erreur',
            self::NeedReturned => 'Besoin de nouveau présent',
            self::Other => 'Autre motif',
        };
    }

    public function needsComment(): bool
    {
        return self::Other === $this;
    }
}
