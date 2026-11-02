<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Point d'extension : un module peut s'opposer à la désactivation d'un compte (ex. dernier administrateur
 * d'une commune) sans que Identity dépende de ce module. Appelé dans la transaction de désactivation,
 * sur l'état verrouillé et rafraîchi de l'utilisateur ; doit lever OperationNotAllowed pour refuser.
 */
#[AutoconfigureTag(UserDeactivationGuard::TAG)]
interface UserDeactivationGuard
{
    public const TAG = 'app.user_deactivation_guard';

    public function assertCanDeactivate(User $user): void;
}
