<?php

declare(strict_types=1);

namespace App\Retention\Application;

/** Rapport d'une exécution (notice ou purge) : comptes seulement, aucune donnée personnelle. */
final class RetentionRunReport
{
    public int $candidates = 0;
    public int $done = 0;
    public int $notEligible = 0;
    public int $alreadyDone = 0;
    public int $sharedPurged = 0;
    public int $failed = 0;
    public int $files = 0;
    public int $registerFileFailures = 0;
    public bool $interrupted = false;
    /** @var array<string, int> */
    public array $counts = [];
    /** @var array<string, int> */
    public array $reasons = [];
    /** @var list<string> classes d'exception, sans message (aucune donnée) */
    public array $failures = [];

    /** @param array<string, int> $counts */
    public function addCounts(array $counts): void
    {
        foreach ($counts as $table => $n) {
            $this->counts[$table] = ($this->counts[$table] ?? 0) + $n;
        }
    }

    public function reason(string $reason): void
    {
        $this->reasons[$reason] = ($this->reasons[$reason] ?? 0) + 1;
    }
}
