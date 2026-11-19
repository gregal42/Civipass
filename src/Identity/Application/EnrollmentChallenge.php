<?php

declare(strict_types=1);

namespace App\Identity\Application;

/** Secret TOTP en cours d'inscription (affiché à l'écran d'activation seulement) et adresse de provisionnement pour le QR. */
final readonly class EnrollmentChallenge
{
    public function __construct(#[\SensitiveParameter] public string $secret, #[\SensitiveParameter] public string $uri)
    {
    }

    /** Secret groupé par quatre pour la saisie manuelle. */
    public function grouped(): string
    {
        return trim(chunk_split($this->secret, 4, ' '));
    }
}
