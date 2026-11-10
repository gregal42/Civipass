<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/** Motifs d'une CORRECTION de libellé de zone (DM-119 §8, validé le 10 novembre 2026) : un code seul, aucun commentaire, aucune notification. */
enum LabelCorrectionReason: string
{
    case Typo = 'TYPO';
    case WordingClarified = 'WORDING_CLARIFIED';

    public function label(): string
    {
        return match ($this) {
            self::Typo => 'Faute de frappe',
            self::WordingClarified => 'Libellé précisé',
        };
    }
}
