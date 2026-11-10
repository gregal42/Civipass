<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/** Motifs du REMPLACEMENT du QR code (DM-119 §8, validé le 10 novembre 2026 pour le LOT 05.5.5) : obligatoire, toujours le vrai motif. Jamais public, jamais notifié ; aucun jeton dans le commentaire. */
enum TokenReplacementReason: string
{
    case Lost = 'LOST';
    case SharedOrPublished = 'SHARED_OR_PUBLISHED';
    case SuspectedMisuse = 'SUSPECTED_MISUSE';
    case DeviceCompromised = 'DEVICE_COMPROMISED';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Lost => 'QR code perdu',
            self::SharedOrPublished => 'QR code partagé ou publié',
            self::SuspectedMisuse => 'Usage frauduleux suspecté',
            self::DeviceCompromised => 'Appareil ou impression compromis',
            self::Other => 'Autre motif',
        };
    }

    public function needsComment(): bool
    {
        return self::Other === $this;
    }
}
