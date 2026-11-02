<?php

declare(strict_types=1);

namespace App\Municipality\Domain;

interface PhoneNumberNormalizer
{
    /**
     * @throws \App\Shared\Domain\DomainRuleViolation si le numéro est invalide
     */
    public function normalize(string $raw): PhoneNumber;
}
