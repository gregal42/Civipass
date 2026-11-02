<?php

declare(strict_types=1);

namespace App\Municipality\Application;

use App\Identity\Domain\User;
use App\Municipality\Domain\MembershipRepository;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityMembership;
use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\MunicipalityStatus;

/**
 * Droit effectif : utilisateur actif ET membership actif ET commune compatible.
 * - ACTIVE : lecture et écriture ;
 * - SUSPENDED : lecture seule ;
 * - INACTIVE : aucun accès.
 * Un superadmin n'obtient aucun droit communal implicite via les rôles communaux (supervision explicite).
 */
final readonly class MembershipAccessPolicy
{
    public function __construct(private MembershipRepository $memberships)
    {
    }

    public function effectiveMembership(User $user, Municipality $municipality, bool $write): ?MunicipalityMembership
    {
        if (!$user->isActive()) {
            return null;
        }
        $allowed = $write
            ? MunicipalityStatus::Active === $municipality->status()
            : \in_array($municipality->status(), [MunicipalityStatus::Active, MunicipalityStatus::Suspended], true);
        if (!$allowed) {
            return null;
        }
        $membership = $this->memberships->findOneByUserAndMunicipality($user, $municipality);

        return null !== $membership && $membership->isActive() ? $membership : null;
    }

    public function hasRole(User $user, Municipality $municipality, MunicipalityRole $role, bool $write = true): bool
    {
        return $this->effectiveMembership($user, $municipality, $write)?->hasRole($role) ?? false;
    }

    public function canManageMembers(User $user, Municipality $municipality): bool
    {
        return $this->isActiveSuperAdmin($user)
            || $this->hasRole($user, $municipality, MunicipalityRole::MunicipalityAdmin);
    }

    public function canEditContact(User $user, Municipality $municipality): bool
    {
        return $this->canManageMembers($user, $municipality);
    }

    public function canReadAudit(User $user, Municipality $municipality): bool
    {
        return $this->isActiveSuperAdmin($user)
            || $this->hasRole($user, $municipality, MunicipalityRole::MunicipalityAdmin, write: false);
    }

    public function isActiveSuperAdmin(User $user): bool
    {
        return $user->isActive() && $user->isSuperAdmin();
    }
}
