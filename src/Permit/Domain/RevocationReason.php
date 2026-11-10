<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/**
 * Motifs de révocation d'une autorisation communale (DM-119 §8, catalogue validé le 10 novembre 2026). Le code est stocké ; le libellé français
 * ne sert qu'à l'écran de l'agent : un motif n'est JAMAIS affiché sur la page publique, dans une notification ni dans la ligne neutre.
 */
enum RevocationReason: string
{
    case FalseStatement = 'FALSE_STATEMENT';
    case EligibilityLost = 'ELIGIBILITY_LOST';
    case PublicSafety = 'PUBLIC_SAFETY';
    case IssuedInError = 'ISSUED_IN_ERROR';
    case Duplicate = 'DUPLICATE';
    case Misuse = 'MISUSE';
    case BeneficiaryRequest = 'BENEFICIARY_REQUEST';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::FalseStatement => 'Fausse déclaration',
            self::EligibilityLost => 'Conditions d’éligibilité non remplies',
            self::PublicSafety => 'Sécurité ou ordre public',
            self::IssuedInError => 'Délivrée par erreur',
            self::Duplicate => 'Doublon',
            self::Misuse => 'Usage non conforme',
            self::BeneficiaryRequest => 'Demande du bénéficiaire',
            self::Other => 'Autre motif',
        };
    }

    /** Un commentaire est obligatoire pour « Autre motif ». */
    public function needsComment(): bool
    {
        return self::Other === $this;
    }
}
