<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/** Motifs d'un RETRAIT de zone (DM-119 §8, validé le 10 novembre 2026 pour le LOT 05.5.3) : obligatoire. Jamais public, jamais notifié. */
enum ZoneRemovalReason: string
{
    case NeedEnded = 'NEED_ENDED';
    case ZoneClosedOrUnsafe = 'ZONE_CLOSED_OR_UNSAFE';
    case IssuedInError = 'ISSUED_IN_ERROR';
    case BeneficiaryRequest = 'BENEFICIARY_REQUEST';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::NeedEnded => 'Besoin terminé',
            self::ZoneClosedOrUnsafe => 'Zone fermée ou dangereuse',
            self::IssuedInError => 'Accordée par erreur',
            self::BeneficiaryRequest => 'Demande du bénéficiaire',
            self::Other => 'Autre motif',
        };
    }

    public function needsComment(): bool
    {
        return self::Other === $this;
    }
}
