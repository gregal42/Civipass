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
}
