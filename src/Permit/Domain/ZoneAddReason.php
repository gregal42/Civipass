<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/** Motifs d'un AJOUT de zone (DM-119 §8, validé le 10 novembre 2026 pour le LOT 05.5.3) : facultatif. Jamais public, jamais notifié. */
enum ZoneAddReason: string
{
    case NewNeed = 'NEW_NEED';
    case OmittedAtDecision = 'OMITTED_AT_DECISION';
    case BeneficiaryRequest = 'BENEFICIARY_REQUEST';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::NewNeed => 'Nouveau besoin d’accès',
            self::OmittedAtDecision => 'Zone oubliée à la décision',
            self::BeneficiaryRequest => 'Demande du bénéficiaire',
            self::Other => 'Autre motif',
        };
    }

    public function needsComment(): bool
    {
        return self::Other === $this;
    }
}
