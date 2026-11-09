<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Shared\Domain\DomainRuleViolation;

/**
 * Référence d'un laissez-passer : `LP-AAAA-NNNN` (DM-105). Compteur annuel distinct de celui des demandes. C'est une référence
 * d'AFFICHAGE : jamais un secret, jamais un moyen d'accès ni de recherche publique (DM-112).
 */
final readonly class PermitReference
{
    private const PATTERN = '/^LP-(\d{4})-(\d{4,})$/';

    private function __construct(public string $value)
    {
    }

    public static function of(int $year, int $number): self
    {
        if ($year < 1000 || $year > 9999) {
            throw new DomainRuleViolation('Année de référence invalide.');
        }
        if ($number < 1) {
            throw new DomainRuleViolation('Le numéro de référence doit être strictement positif.');
        }

        return new self(sprintf('LP-%04d-%04d', $year, $number));
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new DomainRuleViolation('Référence de laissez-passer invalide.');
        }

        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
