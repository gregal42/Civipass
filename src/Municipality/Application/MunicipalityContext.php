<?php

declare(strict_types=1);

namespace App\Municipality\Application;

use App\Identity\Domain\User;
use App\Municipality\Domain\MunicipalityMembership;
use App\Municipality\Domain\MunicipalityRepository;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Component\Uid\Uuid;

/**
 * Résout la commune courante d'un utilisateur à partir d'un identifiant NON FIABLE (session, requête)
 * en vérifiant côté serveur l'appartenance et l'activité du membership.
 */
final readonly class MunicipalityContext
{
    public function __construct(
        private MunicipalityRepository $municipalities,
        private MembershipAccessPolicy $policy,
    ) {
    }

    public function requireMembership(User $user, string $municipalityId, bool $write = false): MunicipalityMembership
    {
        if (!Uuid::isValid($municipalityId)) {
            throw new OperationNotAllowed('Commune inaccessible.');
        }
        $municipality = $this->municipalities->find(Uuid::fromString($municipalityId));
        $membership = null === $municipality ? null : $this->policy->effectiveMembership($user, $municipality, $write);
        if (null === $membership) {
            // Même message pour « inexistante » et « non autorisée » : pas d'énumération des communes.
            throw new OperationNotAllowed('Commune inaccessible.');
        }

        return $membership;
    }
}
