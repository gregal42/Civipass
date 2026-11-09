<?php

declare(strict_types=1);

namespace App\Request\Domain;

use Symfony\Component\Uid\Uuid;

interface PermitRequestRepository
{
    /** Persiste le dossier (parts, zones, véhicules et pièces suivent par cascade) et l'empreinte d'idempotence du dépôt. */
    public function add(PermitRequest $request, RequestIdempotencyKey $key): void;

    /**
     * Dossier déjà créé par une tentative de même clé pour cet événement, le cas échéant. `$locking` : lecture VERROUILLANTE (dans une
     * transaction) qui voit ce qu'une tentative concurrente vient de valider, au lieu de l'instantané de la transaction (REPEATABLE READ).
     */
    public function findByIdempotencyKey(Uuid $eventId, string $keyHash, bool $locking = false): ?PermitRequest;

    /**
     * Décisions du dossier relues sous verrou partagé (état validé le plus récent, jamais l'instantané) : sert à restituer, au rejeu d'une
     * délivrance concurrente, la décision d'origine.
     *
     * @return list<PermitRequestDecision>
     */
    public function lockDecisions(Uuid $requestId): array;

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

    /**
     * Part de la commune pour la demande de cette référence publique (nulle si la référence est inconnue OU ne concerne pas la commune : même
     * réponse). Lecture seule, sans verrou ; sert la recherche des corrections d'identité (LOT 04.5), jamais l'instruction.
     */
    public function findPartByReference(string $reference, Uuid $municipalityId): ?PermitRequestMunicipality;

    /** Relit la part SANS verrou (prévisualisation en lecture seule) ; nulle si elle n'appartient pas à cette commune. */
    public function findPart(Uuid $partId, Uuid $municipalityId): ?PermitRequestMunicipality;

    /**
     * Relit le dossier SOUS VERROU exclusif (état courant, jamais l'instantané) pour une correction d'identité (DM-111). Ne prend AUCUN autre verrou et
     * doit être le seul verrou de sa transaction : l'identité n'est lue ni écrite par la décision, aucun cycle avec l'ordre DT-024 n'est possible.
     * L'appelant a déjà vérifié par `locatePart()` que le dossier concerne SA commune.
     */
    public function lockRequest(Uuid $requestId): ?PermitRequest;
}
