<?php

declare(strict_types=1);

namespace App\Retention\Domain;

/** Résultat de la vérification des points d'ancrage contre la chaîne du registre (LOT 05.6.7). */
final readonly class AnchorReport
{
    public const FILE_ABSENT = 'ANCHOR_FILE_ABSENT';
    public const INVALID = 'ANCHOR_LINE_INVALID';
    public const MISMATCH = 'ANCHOR_MISMATCH';
    public const CHAIN_TRUNCATED = 'CHAIN_SHORTER_THAN_ANCHOR';
    public const EXTERNAL_ABSENT = 'EXTERNAL_ANCHOR_FILE_ABSENT';

    /**
     * @param list<string>              $problems
     * @param array<string, mixed>|null $last     dernier point d'ancrage lu (instantané de repères monotones)
     */
    public function __construct(public int $anchors, public array $problems, public ?array $last)
    {
    }

    public function sound(): bool
    {
        return [] === $this->problems;
    }
}
