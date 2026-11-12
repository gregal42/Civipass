<?php

declare(strict_types=1);

namespace App\Archive\Domain;

/** Motifs de gel de conservation d'un dossier communal (LOT 05.6.3) ; `AUTRE` exige un commentaire. Liste PROVISOIRE, à valider avec le DPO et les archives. */
enum LegalHoldReason: string
{
    case Litigation = 'LITIGE';
    case Appeal = 'RECOURS';
    case Requisition = 'REQUISITION';
    case SubjectRequest = 'DEMANDE_PERSONNE';
    case Other = 'AUTRE';

    public function label(): string
    {
        return match ($this) {
            self::Litigation => 'Litige en cours',
            self::Appeal => 'Recours',
            self::Requisition => 'Réquisition',
            self::SubjectRequest => 'Demande de la personne concernée',
            self::Other => 'Autre motif (à préciser)',
        };
    }
}
