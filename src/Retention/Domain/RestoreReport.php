<?php

declare(strict_types=1);

namespace App\Retention\Domain;

/** Rapport du contrôle de restauration (LOT 05.6.7). L'empreinte identifie l'état constaté : un rejeu n'est accepté que pour l'empreinte PRÉSENTÉE. */
final readonly class RestoreReport
{
    /**
     * @param list<RestoreItem> $items
     * @param list<string>      $proofProblems preuve externe absente, rompue ou incohérente : aucun rejeu
     */
    public function __construct(public array $items, public array $proofProblems, public string $hash, public int $chainLines, public int $anchors)
    {
    }

    public function proofSound(): bool
    {
        return [] === $this->proofProblems;
    }

    /** @return list<RestoreItem> */
    public function blocking(): array
    {
        return array_values(array_filter($this->items, static fn (RestoreItem $i): bool => $i->blocking()));
    }

    /** @return list<RestoreItem> */
    public function replayable(string $code): array
    {
        return array_values(array_filter($this->items, static fn (RestoreItem $i): bool => $i->code === $code && RestoreItem::REPLAYABLE === $i->qualification));
    }

    /** @return list<RestoreItem> */
    public function byCode(string $code): array
    {
        return array_values(array_filter($this->items, static fn (RestoreItem $i): bool => $i->code === $code));
    }
}
