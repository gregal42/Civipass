<?php

declare(strict_types=1);

namespace App\Municipality\Domain;

/**
 * Un droit d'utilisation d'une commune, avec sa source et sa fin de validité facultative (null = sans expiration).
 */
final readonly class Entitlement
{
    public function __construct(
        public EntitlementKind $kind,
        public ?\DateTimeImmutable $validUntil = null,
    ) {
    }

    public function isValidAt(\DateTimeImmutable $at): bool
    {
        return null === $this->validUntil || $at < $this->validUntil;
    }
}
