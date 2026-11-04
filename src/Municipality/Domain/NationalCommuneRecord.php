<?php

declare(strict_types=1);

namespace App\Municipality\Domain;

/**
 * Commune telle que la source officielle la décrit (API Géo / COG INSEE) : donnée brute du référentiel, jamais une commune
 * inscrite sur Civipass.
 */
final readonly class NationalCommuneRecord
{
    /**
     * @param list<string> $postalCodes
     */
    public function __construct(
        public string $inseeCode,
        public string $name,
        public string $departmentCode,
        public ?string $regionCode = null,
        public array $postalCodes = [],
        public ?int $population = null,
    ) {
    }
}
