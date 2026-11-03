<?php

declare(strict_types=1);

namespace App\Municipality\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * Aucune suppression : l'historique des accès (expirés ou révoqués) est conservé.
 */
interface MunicipalityCommercialAccessRepository
{
    public function add(MunicipalityCommercialAccess $access): void;

    public function find(Uuid $id): ?MunicipalityCommercialAccess;

    /**
     * Historique complet d'une commune, du plus récent au plus ancien.
     *
     * @return list<MunicipalityCommercialAccess>
     */
    public function findByMunicipality(Uuid $municipalityId): array;

    /**
     * Accès valides (attribués, ni expirés ni révoqués) à l'instant donné.
     *
     * @return list<MunicipalityCommercialAccess>
     */
    public function findValidAt(Uuid $municipalityId, \DateTimeImmutable $at): array;
}
