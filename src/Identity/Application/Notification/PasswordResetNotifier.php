<?php

declare(strict_types=1);

namespace App\Identity\Application\Notification;

use App\Identity\Domain\User;

/**
 * Abstraction de notification : l'implémentation V1 envoie les emails à la fin de la requête (kernel.terminate) ;
 * elle pourra être remplacée par un message Messenger asynchrone (LOT 07) sans toucher aux cas d'usage.
 */
interface PasswordResetNotifier
{
    public function sendResetLink(User $user, string $plainToken, \DateTimeImmutable $expiresAt): void;

    public function sendPasswordChanged(User $user): void;
}
