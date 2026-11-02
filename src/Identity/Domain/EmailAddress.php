<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Shared\Domain\DomainRuleViolation;

/**
 * Email normalisé : trim, Unicode NFC, minuscules. Aucune logique propre à un fournisseur.
 */
final readonly class EmailAddress implements \Stringable
{
    public const MAX_LENGTH = 180;

    private function __construct(public string $value)
    {
    }

    public static function fromString(string $raw): self
    {
        $normalized = \Normalizer::normalize(trim($raw), \Normalizer::FORM_C);
        if (false === $normalized) {
            throw new DomainRuleViolation('Adresse email invalide.');
        }
        $normalized = mb_strtolower($normalized);

        if ('' === $normalized || mb_strlen($normalized) > self::MAX_LENGTH) {
            throw new DomainRuleViolation(sprintf('Adresse email invalide : longueur comprise entre 1 et %d caractères attendue.', self::MAX_LENGTH));
        }
        if (1 === preg_match('/[\s\p{C}]/u', $normalized)) {
            throw new DomainRuleViolation('Adresse email invalide : espaces et caractères de contrôle interdits.');
        }
        if (false === filter_var($normalized, \FILTER_VALIDATE_EMAIL, \FILTER_FLAG_EMAIL_UNICODE)) {
            throw new DomainRuleViolation('Adresse email invalide.');
        }

        return new self($normalized);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
