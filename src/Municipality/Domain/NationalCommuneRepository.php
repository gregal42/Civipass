<?php

declare(strict_types=1);

namespace App\Municipality\Domain;

/**
 * Persistance du référentiel national. Aucune suppression physique : les communes disparues de la source sont désactivées.
 */
interface NationalCommuneRepository
{
    public function add(NationalCommune $commune): void;

    public function findByInseeCode(InseeCode $code): ?NationalCommune;

    /**
     * Communes (actives ou non) pour des codes INSEE donnés, indexées par code, en une requête.
     *
     * @param list<string> $inseeCodes
     *
     * @return array<string, NationalCommune>
     */
    public function findByInseeCodes(array $inseeCodes): array;

    /**
     * Marque comme vues par la synchronisation `$syncId` les communes de ces codes (pas de modification de leurs champs).
     *
     * @param list<string> $inseeCodes
     */
    public function markSeen(array $inseeCodes, string $syncId, \DateTimeImmutable $at): void;

    /**
     * Désactive les communes actives que la synchronisation `$syncId` n'a pas vues ; renvoie leur nombre.
     */
    public function deactivateNotSeenIn(string $syncId, \DateTimeImmutable $now): int;

    public function countActive(): int;
}
