<?php

declare(strict_types=1);

namespace App\Request\Application\Read;

use App\Request\Domain\MunicipalRequestStatus;

/**
 * Ce que montre l'écran de correction d'identité : l'identité actuelle, la référence, le statut de la portion de SA commune (rien d'autre
 * du dossier : ni zones, ni véhicules, ni justificatifs) et le nombre d'AUTRES communes qui verront la correction. `correctable` : la participation de la commune est ACTIVE (sinon `unavailableReason`
 * explique la lecture seule).
 */
final readonly class IdentityCorrectionPage
{
    public function __construct(
        public string $requestId,
        public string $reference,
        public string $eventName,
        public MunicipalRequestStatus $status,
        public string $lastName,
        public string $firstName,
        public string $address,
        public string $email,
        public string $phone,
        public int $version,
        public int $otherMunicipalities,
        public bool $consultable,
        public bool $correctable,
        public string $unavailableReason,
    ) {
    }
}
