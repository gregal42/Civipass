<?php

declare(strict_types=1);

namespace App\Archive\Domain;

/** Motifs de consultation d'une archive (LOT 05.6.3) ; `AUTRE` exige un commentaire. Liste PROVISOIRE, à valider avec le DPO et les archives. */
enum ConsultationReason: string
{
    case Litigation = 'LITIGE';
    case Appeal = 'RECOURS';
    case SubjectRequest = 'DEMANDE_PERSONNE';
    case InternalCheck = 'CONTROLE_INTERNE';
    case LegalObligation = 'OBLIGATION_LEGALE';
    case Other = 'AUTRE';

    public function label(): string
    {
        return match ($this) {
            self::Litigation => 'Litige',
            self::Appeal => 'Recours',
            self::SubjectRequest => 'Demande de la personne concernée',
            self::InternalCheck => 'Contrôle interne',
            self::LegalObligation => 'Obligation légale',
            self::Other => 'Autre motif (à préciser)',
        };
    }
}
