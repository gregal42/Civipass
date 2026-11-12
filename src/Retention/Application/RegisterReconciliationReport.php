<?php

declare(strict_types=1);

namespace App\Retention\Application;

/** Écarts entre le registre SQL et le registre hors base (identifiants seulement). */
final class RegisterReconciliationReport
{
    /** @var list<string> lignes SQL sans ligne de fichier (écriture hors transaction échouée ou interrompue) */
    public array $missingInFile = [];
    /** @var list<string> lignes de fichier sans ligne SQL (base restaurée avant la purge, ou altération) */
    public array $fileOnly = [];
    public int $invalidLines = 0;
    public int $repaired = 0;

    public function consistent(): bool
    {
        return [] === $this->missingInFile && [] === $this->fileOnly && 0 === $this->invalidLines;
    }
}
