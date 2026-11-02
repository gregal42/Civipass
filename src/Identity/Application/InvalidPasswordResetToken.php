<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Shared\Domain\DomainRuleViolation;

/**
 * Jeton inconnu, expiré, déjà utilisé ou compte non éligible : une seule exception, un seul message,
 * pour ne rien révéler de la cause.
 */
final class InvalidPasswordResetToken extends DomainRuleViolation
{
    public function __construct()
    {
        parent::__construct('Ce lien de réinitialisation est invalide ou a expiré.');
    }
}
