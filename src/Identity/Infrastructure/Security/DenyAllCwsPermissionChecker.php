<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\CwsPermissionChecker;
use App\Identity\Domain\CwsPermission;
use App\Identity\Domain\User;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Refus par défaut (DM-074) : personne ne détient ces permissions tant que le lot Administration CWS n'a pas
 * défini leur attribution. `superAdmin` n'y change rien. À remplacer par une implémentation persistante,
 * pas à contourner.
 */
#[AsAlias(CwsPermissionChecker::class)]
final readonly class DenyAllCwsPermissionChecker implements CwsPermissionChecker
{
    public function isGranted(User $user, CwsPermission $permission): bool
    {
        return false;
    }
}
