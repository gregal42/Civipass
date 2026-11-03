<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Identity\Domain\User;
use App\Municipality\Domain\MembershipRepository;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityMembership;
use Symfony\Component\Uid\Uuid;

/**
 * Dépôt en mémoire (tests unitaires des politiques) : de vrais objets du domaine, aucune base, aucun mock.
 */
final class InMemoryMembershipRepository implements MembershipRepository
{
    /** @var list<MunicipalityMembership> */
    private array $memberships = [];

    public function add(MunicipalityMembership $membership): void
    {
        $this->memberships[] = $membership;
    }

    public function find(Uuid $id): ?MunicipalityMembership
    {
        foreach ($this->memberships as $membership) {
            if ($membership->id()->equals($id)) {
                return $membership;
            }
        }

        return null;
    }

    public function findOneByUserAndMunicipality(User $user, Municipality $municipality): ?MunicipalityMembership
    {
        foreach ($this->memberships as $membership) {
            if ($membership->user()->id()->equals($user->id()) && $membership->municipality()->id()->equals($municipality->id())) {
                return $membership;
            }
        }

        return null;
    }

    public function countEffectiveAdmins(Municipality $municipality, ?Uuid $excludingMembershipId = null, ?Uuid $excludingUserId = null): int
    {
        throw new \LogicException('Non utilisé par les politiques du module Event.');
    }

    public function findActiveMunicipalityIdsAdministeredBy(User $user): array
    {
        throw new \LogicException('Non utilisé par les politiques du module Event.');
    }

    public function findActiveByUser(User $user): array
    {
        return array_values(array_filter(
            $this->memberships,
            static fn (MunicipalityMembership $m): bool => $m->isActive() && $m->user()->id()->equals($user->id()),
        ));
    }

    public function findByMunicipality(Municipality $municipality): array
    {
        return array_values(array_filter(
            $this->memberships,
            static fn (MunicipalityMembership $m): bool => $m->municipality()->id()->equals($municipality->id()),
        ));
    }
}
