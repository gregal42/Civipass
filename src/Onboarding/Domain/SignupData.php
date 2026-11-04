<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

/** Saisies de création de compte d'un administrateur invité (l'adresse e-mail est celle du lien, jamais saisie). */
final readonly class SignupData
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        #[\SensitiveParameter]
        public string $password,
    ) {
    }
}
