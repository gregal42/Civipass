<?php

declare(strict_types=1);

namespace App\Retention\Application;

/** Écarts entre le registre SQL et le registre hors base, chaîne d'empreintes et ancrage compris (identifiants et codes seulement). */
final class RegisterReconciliationReport
{
    /** @var list<string> lignes SQL sans ligne de fichier (écriture hors transaction échouée ou interrompue) */
    public array $missingInFile = [];
    /** @var list<string> lignes de fichier sans ligne SQL (base restaurée avant la purge, ou altération) */
    public array $fileOnly = [];
    /** @var list<string> ruptures de chaîne `CODE@ligne` : suppression, modification ou réordonnancement : INCIDENT, jamais réparé */
    public array $chainBreaks = [];
    /** @var list<string> problèmes d'ancrage (réécriture complète, troncature, fichier absent) */
    public array $anchorProblems = [];
    public int $invalidLines = 0;
    public int $repaired = 0;
    public int $adopted = 0;

    public function consistent(): bool
    {
        return [] === $this->missingInFile && [] === $this->fileOnly && [] === $this->chainBreaks && [] === $this->anchorProblems && 0 === $this->invalidLines;
    }
}
