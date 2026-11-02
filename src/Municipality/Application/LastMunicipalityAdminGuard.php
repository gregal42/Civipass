<?php

declare(strict_types=1);

namespace App\Municipality\Application;

use App\Identity\Application\UserDeactivationGuard;
use App\Identity\Domain\User;
use App\Municipality\Domain\MembershipRepository;
use App\Municipality\Domain\MunicipalityRepository;
use App\Municipality\Domain\MunicipalityStatus;
use App\Shared\Domain\OperationNotAllowed;

/**
 * Aucun compte ne peut être désactivé s'il laisse une commune ACTIVE sans administrateur effectif.
 * Toutes les communes administrées par l'utilisateur sont verrouillées (ordre stable) puis contrôlées.
 */
final readonly class LastMunicipalityAdminGuard implements UserDeactivationGuard
{
    public function __construct(
        private MembershipRepository $memberships,
        private MunicipalityRepository $municipalities,
    ) {
    }

    public function assertCanDeactivate(User $user): void
    {
        $locked = [];
        foreach ($this->memberships->findActiveMunicipalityIdsAdministeredBy($user) as $id) {
            $municipality = $this->municipalities->lock($id);
            if (null !== $municipality) {
                $locked[] = $municipality;
            }
        }

        foreach ($locked as $municipality) {
            // Réévalué après verrouillage : l'état lu avant le verrou a pu changer.
            if (MunicipalityStatus::Active !== $municipality->status()) {
                continue;
            }
            $all = $this->memberships->countEffectiveAdmins($municipality);
            $others = $this->memberships->countEffectiveAdmins($municipality, excludingUserId: $user->id());
            if ($all > $others && 0 === $others) {
                throw new OperationNotAllowed(sprintf('Ce compte est le dernier administrateur actif de la commune « %s » : désignez un autre administrateur avant de le désactiver.', $municipality->name()));
            }
        }
    }
}
