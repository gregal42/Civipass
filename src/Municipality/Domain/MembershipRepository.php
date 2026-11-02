<?php

declare(strict_types=1);

namespace App\Municipality\Domain;

use App\Identity\Domain\User;
use Symfony\Component\Uid\Uuid;

interface MembershipRepository
{
    public function add(MunicipalityMembership $membership): void;

    public function find(Uuid $id): ?MunicipalityMembership;

    public function findOneByUserAndMunicipality(User $user, Municipality $municipality): ?MunicipalityMembership;

    /**
     * Administrateurs EFFECTIFS : membership actif, compte actif, rôle MUNICIPALITY_ADMIN (requête COUNT).
     */
    public function countEffectiveAdmins(Municipality $municipality, ?Uuid $excludingMembershipId = null, ?Uuid $excludingUserId = null): int;

    /**
     * Communes ACTIVES dont l'utilisateur est administrateur via un membership actif, triées par identifiant
     * (ordre de verrouillage stable).
     *
     * @return list<Uuid>
     */
    public function findActiveMunicipalityIdsAdministeredBy(User $user): array;

    /**
     * @return list<MunicipalityMembership>
     */
    public function findByMunicipality(Municipality $municipality): array;
}
