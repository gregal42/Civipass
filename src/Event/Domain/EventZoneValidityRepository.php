<?php

declare(strict_types=1);

namespace App\Event\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * Aucune suppression physique : les créneaux se suppriment logiquement et restent consultables.
 */
interface EventZoneValidityRepository
{
    public function add(EventZoneValidity $validity): void;

    public function find(Uuid $id): ?EventZoneValidity;

    /**
     * Créneaux non supprimés d'une zone (ceux qui comptent pour le calcul des droits).
     *
     * @return list<EventZoneValidity>
     */
    public function findActiveByZone(Uuid $zoneId): array;

    /**
     * Créneaux non supprimés de plusieurs zones en une requête (pas de chargement en boucle).
     *
     * @param list<Uuid> $zoneIds
     *
     * @return list<EventZoneValidity>
     */
    public function findActiveByZones(array $zoneIds): array;

    /**
     * Historique complet d'une zone, créneaux supprimés compris, du plus ancien au plus récent.
     *
     * @return list<EventZoneValidity>
     */
    public function findHistoryByZone(Uuid $zoneId): array;
}
