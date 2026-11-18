<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\CwsPermissionChecker;
use App\Identity\Domain\CwsPermission;
use App\Identity\Domain\CwsPermissionGrantRepository;
use App\Identity\Domain\User;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Habilitation CWS persistée (LOT 08.6, DM-139) : relue en base à CHAQUE appel (jamais mise en cache), et seulement pour un super-administrateur ACTIF.
 * `superAdmin` seul ne donne jamais ces permissions (DM-074) ; aucune attribution = refus. Remplace le refus par défaut (DenyAllCwsPermissionChecker, conservé
 * comme référence du comportement « rien n'est attribué »).
 */
#[AsAlias(CwsPermissionChecker::class)]
final readonly class PersistentCwsPermissionChecker implements CwsPermissionChecker
{
    public function __construct(private CwsPermissionGrantRepository $grants)
    {
    }

    public function isGranted(User $user, CwsPermission $permission): bool
    {
        return $user->isActive() && $user->isSuperAdmin() && null !== $this->grants->findActive($user->id(), $permission);
    }
}
