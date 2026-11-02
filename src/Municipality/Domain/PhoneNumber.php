<?php

declare(strict_types=1);

namespace App\Municipality\Domain;

use App\Shared\Domain\DomainRuleViolation;

/**
 * Numéro au format E.164 (+ suivi de 8 à 15 chiffres). Construit par un PhoneNumberNormalizer.
 */
final readonly class PhoneNumber implements \Stringable
{
    private function __construct(public string $e164)
    {
    }

    public static function fromE164(string $e164): self
    {
        if (1 !== preg_match('/^\+[1-9]\d{7,14}$/', $e164)) {
            throw new DomainRuleViolation('Numéro de téléphone invalide.');
        }

        return new self($e164);
    }

    public function __toString(): string
    {
        return $this->e164;
    }
}
