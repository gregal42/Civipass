<?php

declare(strict_types=1);

namespace App\Retention\Domain;

/** Résultat d'un rejeu (LOT 05.6.7) : effectifs et motifs seulement. */
final class ReplayReport
{
    public bool $executed = false;
    public int $plannedDossiers = 0;
    public int $plannedShared = 0;
    public int $dossiersReplayed = 0;
    public int $sharedReplayed = 0;
    public int $alreadyDone = 0;
    public int $blocked = 0;
    public int $adopted = 0;
    public int $failed = 0;
    public int $registerFileFailures = 0;
    public bool $interrupted = false;
    /** @var array<string, int> */
    public array $reasons = [];
    public string $batch = '';

    public function reason(string $reason): void
    {
        $this->reasons[$reason] = ($this->reasons[$reason] ?? 0) + 1;
    }
}
