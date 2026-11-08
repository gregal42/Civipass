<?php

declare(strict_types=1);

namespace App\Request\Domain;

use Symfony\Component\Uid\Uuid;

interface PermitRequestRepository
{
    /** Persiste le dossier (parts, zones, véhicules et pièces suivent par cascade) et l'empreinte d'idempotence du dépôt. */
    public function add(PermitRequest $request, RequestIdempotencyKey $key): void;

    /** Dossier déjà créé par une tentative de même clé pour cet événement, le cas échéant. */
    public function findByIdempotencyKey(Uuid $eventId, string $keyHash): ?PermitRequest;

    /**
     * Repère, sans verrou ni entité, la part de la commune `$municipalityId` pour ce dossier (nulle si le dossier n'a aucune part
     * pour cette commune : ressource inexistante ou étrangère, même refus).
     */
    public function locatePart(Uuid $requestId, Uuid $municipalityId): ?PartLocator;

    /**
     * Relit la part SOUS VERROU exclusif (état courant, jamais l'instantané) ; nulle si elle n'appartient pas à cette commune.
     * À appeler dans la transaction, après l'événement, la participation et les zones (DT-024, DT-041).
     */
    public function lockPart(Uuid $partId, Uuid $municipalityId): ?PermitRequestMunicipality;

    /** Relit la part SANS verrou (prévisualisation en lecture seule) ; nulle si elle n'appartient pas à cette commune. */
    public function findPart(Uuid $partId, Uuid $municipalityId): ?PermitRequestMunicipality;
}
