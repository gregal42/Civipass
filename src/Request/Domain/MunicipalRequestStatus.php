<?php

declare(strict_types=1);

namespace App\Request\Domain;

/**
 * Statut de la part communale d'une demande (SFD §2, CDC §7.1). Les décisions sont indépendantes par commune : il
 * n'existe aucun statut global. Au LOT 03, seul `Pending` est écrit ; l'approbation et le refus sont du LOT 04.
 */
enum MunicipalRequestStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Refused = 'REFUSED';
}
