<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Observateur de PERTE D'ACCÈS (LOT 05.6.3) : appelé DANS la transaction de `MembershipManager` et `UserManager`, après la modification, pour que les habilitations
 * fines qui s'appuient sur un rôle ou une appartenance (désignation d'archive, coordination statistique) soient RÉVOQUÉES explicitement, avec leur cause, et ne
 * reviennent jamais d'elles-mêmes si le rôle ou le compte est rendu. Le recontrôle à chaque accès reste la défense en profondeur.
 */
#[AutoconfigureTag(AccessLossObserver::TAG)]
interface AccessLossObserver
{
    public const TAG = 'app.access_loss_observer';

    /** Le rôle `$role` n'est plus porté par l'utilisateur dans la commune (rôle retiré). */
    public function roleLost(User $user, Municipality $municipality, MunicipalityRole $role, ?User $actor, \DateTimeImmutable $now): void;

    /** L'appartenance de l'utilisateur à la commune est désactivée. */
    public function membershipDeactivated(User $user, Municipality $municipality, ?User $actor, \DateTimeImmutable $now): void;

    /** Le compte de l'utilisateur est désactivé (toutes communes). */
    public function userDeactivated(User $user, ?User $actor, \DateTimeImmutable $now): void;
}
