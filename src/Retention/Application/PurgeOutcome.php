<?php

declare(strict_types=1);

namespace App\Retention\Application;

use App\Retention\Domain\PurgeRecord;

/** Résultat d'une opération de purge (jamais de donnée personnelle). */
final readonly class PurgeOutcome
{
    public const PURGED = 'PURGED';
    public const SIMULATED = 'SIMULATED';
    public const ALREADY_DONE = 'ALREADY_DONE';
    public const NOT_ELIGIBLE = 'NOT_ELIGIBLE';
    public const NOT_DUE = 'NOT_DUE';

    /**
     * @param array<string, int> $counts
     */
    public function __construct(
        public string $status,
        public string $reason,
        public array $counts,
        public int $files,
        public ?string $requestId,
        public bool $sharedDue,
        public ?PurgeRecord $record,
    ) {
    }

    /** @param array<string, int> $counts */
    public static function simulated(array $counts, int $files, ?string $requestId, bool $sharedDue): self
    {
        return new self(self::SIMULATED, '', $counts, $files, $requestId, $sharedDue, null);
    }

    public static function skipped(string $status, string $reason): self
    {
        return new self($status, $reason, [], 0, null, false, null);
    }
}
