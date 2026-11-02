<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Enregistre la date de dernière connexion réussie. N'altère pas updatedAt (réservé aux changements de
 * profil et d'état) ; ce n'est pas un journal des connexions.
 */
final readonly class LoginRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function record(User $user): void
    {
        $user->recordLogin($this->clock->now());
        $this->entityManager->flush();
    }
}
