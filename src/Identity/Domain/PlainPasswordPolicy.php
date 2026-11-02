<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Shared\Domain\DomainRuleViolation;

/**
 * 12 caractères minimum, aucune règle de complexité. Maximum : 4096 OCTETS (limite du hasher Symfony,
 * qui mesure avec strlen) : un mot de passe multioctet ne doit pas passer la politique puis échouer au hachage.
 */
final class PlainPasswordPolicy
{
    public const MIN_LENGTH = 12;
    public const MAX_BYTES = 4096;

    public static function assertValid(string $plainPassword): void
    {
        $length = mb_strlen($plainPassword);
        if ($length < self::MIN_LENGTH) {
            throw new DomainRuleViolation(sprintf('Le mot de passe doit contenir au moins %d caractères.', self::MIN_LENGTH));
        }
        if (\strlen($plainPassword) > self::MAX_BYTES) {
            throw new DomainRuleViolation(sprintf('Le mot de passe ne doit pas dépasser %d octets.', self::MAX_BYTES));
        }
    }
}
