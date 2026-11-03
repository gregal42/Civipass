<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\CwsPermission;
use App\Identity\Domain\User;

/**
 * Vérifie une habilitation CWS. Toute fonction CWS sensible (impersonation, clôture forcée) doit passer par ce
 * contrat côté serveur ; tant que les habilitations ne sont pas développées, l'implémentation refuse tout.
 */
interface CwsPermissionChecker
{
    public function isGranted(User $user, CwsPermission $permission): bool;
}
