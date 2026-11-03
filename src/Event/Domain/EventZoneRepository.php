<?php

declare(strict_types=1);

namespace App\Event\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * Aucune suppression physique : les zones se suppriment logiquement (deletedAt).
 */
interface EventZoneRepository
{
    public function add(EventZone $zone): void;

    /** Accès interne (services déjà autorisés) : sans filtre de commune. */
    public function find(Uuid $id): ?EventZone;

    /** Une commune ne peut atteindre que les zones de SA participation (DM-007). */
    public function findForMunicipality(Uuid $zoneId, Uuid $municipalityId): ?EventZone;

    /**
     * Zones non supprimées d'une participation.
     *
     * @return list<EventZone>
     */
    public function findActiveByParticipation(Uuid $participationId): array;

    /**
     * Opérations groupées : renvoie uniquement les zones demandées qui appartiennent à la participation et ne sont pas
     * supprimées ; l'appelant refuse l'opération si le nombre trouvé diffère du nombre demandé (DM-028).
     *
     * @param list<Uuid> $zoneIds
     *
     * @return list<EventZone>
     */
    public function findByIdsInParticipation(array $zoneIds, Uuid $participationId): array;

    /**
     * Garde d'activation (DM-059) : existe-t-il une zone confirmée, active, non supprimée, d'une participation ACTIVE,
     * avec une validité configurée (toute la durée, ou au moins un créneau non supprimé) ?
     */
    public function existsActivationReadyZone(Uuid $eventId): bool;
}
