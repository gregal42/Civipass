<?php

declare(strict_types=1);

namespace App\Request\Application\Command;

/**
 * Saisie brute d'une correction d'identité (champs vides = absents). `expectedVersion` est la version lue à l'ouverture du formulaire ;
 * `sameBeneficiary` l'attestation de l'agent : il s'agit bien de la même personne.
 */
final readonly class CorrectIdentityCommand
{
    public function __construct(
        public int $expectedVersion,
        public string $lastName,
        public string $firstName,
        public string $address,
        public string $email,
        public string $phone,
        public bool $sameBeneficiary,
    ) {
    }
}
