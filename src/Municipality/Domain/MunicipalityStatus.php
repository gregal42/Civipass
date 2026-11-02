<?php

declare(strict_types=1);

namespace App\Municipality\Domain;

/**
 * Statut administratif, indépendant de l'abonnement et de toute participation à un événement.
 */
enum MunicipalityStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Inactive = 'INACTIVE';
}
