<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

use App\Identity\Domain\User;

/** Issue d'une inscription d'administrateur : le compte (existant ou créé) et l'invitation à laquelle il peut répondre. */
final readonly class EnrollmentResult
{
    public function __construct(public User $user, public string $municipalityId, public string $eventId, public bool $accountCreated)
    {
    }
}
