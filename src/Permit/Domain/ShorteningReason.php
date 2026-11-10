<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/** Motifs d'un RACCOURCISSEMENT d'autorisation (DM-119 §8, catalogue validé le 10 novembre 2026) : obligatoire. Jamais exposé publiquement ni notifié. */
enum ShorteningReason: string
{
    case NeedEnded = 'NEED_ENDED';
    case AreaReopened = 'AREA_REOPENED';
    case PublicSafety = 'PUBLIC_SAFETY';
    case IssuedInError = 'ISSUED_IN_ERROR';
    case BeneficiaryRequest = 'BENEFICIARY_REQUEST';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::NeedEnded => 'Besoin terminé',
            self::AreaReopened => 'Zone rouverte, restriction levée',
            self::PublicSafety => 'Sécurité ou ordre public',
            self::IssuedInError => 'Durée accordée par erreur',
            self::BeneficiaryRequest => 'Demande du bénéficiaire',
            self::Other => 'Autre motif',
        };
    }

    public function needsComment(): bool
    {
        return self::Other === $this;
    }
}
