<?php

declare(strict_types=1);

namespace App\Request\Domain;

use App\Shared\Domain\DomainRuleViolation;

/**
 * Plaque française SIV ou FNI (DM-103), normalisée : majuscules, sans espaces ni tirets.
 */
final readonly class LicensePlate
{
    private const SIV = '/^[A-Z]{2}[0-9]{3}[A-Z]{2}$/';
    private const FNI = '/^[0-9]{1,4}[A-Z]{1,3}([0-9]{2}|2A|2B)$/';

    private function __construct(
        public string $value,
        public PlateFormat $format,
    ) {
    }

    public static function fromInput(string $raw): self
    {
        $normalized = mb_strtoupper((string) preg_replace('/[\s\-\x{2010}-\x{2015}]+/u', '', $raw));

        if (1 === preg_match(self::SIV, $normalized)) {
            return new self($normalized, PlateFormat::Siv);
        }
        if (1 === preg_match(self::FNI, $normalized)) {
            return new self($normalized, PlateFormat::Fni);
        }

        throw new DomainRuleViolation('Plaque d\'immatriculation invalide : seules les plaques françaises SIV (AB-123-CD) et FNI (1234 AB 56) sont acceptées.');
    }
}
