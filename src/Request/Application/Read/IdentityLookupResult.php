<?php

declare(strict_types=1);

namespace App\Request\Application\Read;

use App\Request\Domain\MunicipalRequestStatus;

/**
 * Résultat de la recherche d'une demande par référence pour une correction d'identité (LOT 04.5) : le strict nécessaire pour reconnaître le
 * dossier. Ni zones, ni justificatifs, ni décision, ni donnée d'une autre commune ; le statut est celui de la part de SA commune.
 */
final readonly class IdentityLookupResult
{
    public function __construct(
        public string $requestId,
        public string $reference,
        public string $eventName,
        public MunicipalRequestStatus $status,
        public string $lastName,
        public string $firstName,
        public bool $correctable,
        public string $unavailableReason,
    ) {
    }
}
