<?php

declare(strict_types=1);

namespace App\Request\Application\Result;

/** Résultat d'une correction : champs réellement modifiés (vide : aucun changement, rien d'enregistré ni d'audité) et nombre d'autres communes concernées. */
final readonly class IdentityCorrectionResult
{
    /**
     * @param list<string> $changedFields
     */
    public function __construct(
        public string $reference,
        public array $changedFields,
        public int $otherMunicipalities,
    ) {
    }

    public function changed(): bool
    {
        return [] !== $this->changedFields;
    }
}
