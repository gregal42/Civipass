<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\CwsPermissionChecker;
use App\Identity\Domain\CwsPermission;
use App\Identity\Domain\User;

/**
 * Refus total (DM-074) : comportement « aucune attribution ». Depuis le LOT 08.6, l'implémentation active est PersistentCwsPermissionChecker (permissions
 * persistées) ; cette classe n'est plus un service du conteneur et sert de référence du refus par défaut.
 */
final readonly class DenyAllCwsPermissionChecker implements CwsPermissionChecker
{
    public function isGranted(User $user, CwsPermission $permission): bool
    {
        return false;
    }
}
