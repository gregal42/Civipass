<?php

declare(strict_types=1);

namespace App\Retention\Domain;

/** Résultat de la vérification de la chaîne d'empreintes du registre externe (format v2, LOT 05.6.7). */
final readonly class ChainReport
{
    public const BREAK_INVALID = 'LINE_INVALID';
    public const BREAK_UNCHAINED = 'LINE_NOT_CHAINED';
    public const BREAK_SEQUENCE = 'SEQUENCE_BREAK';
    public const BREAK_PREVIOUS = 'PREVIOUS_HASH_MISMATCH';

    /**
     * @param list<array{line: int, code: string}> $breaks
     * @param array<int, string>                   $hashes empreinte de chaque ligne par numéro de séquence
     */
    public function __construct(
        public bool $fileExists,
        public int $lines,
        public int $lastSeq,
        public string $headHash,
        public array $breaks,
        public array $hashes,
    ) {
    }

    public function intact(): bool
    {
        return [] === $this->breaks;
    }
}
