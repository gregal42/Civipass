<?php

declare(strict_types=1);

namespace App\Retention\Application;

use App\Retention\Domain\ChainReport;
use App\Retention\Domain\PurgeRecord;

/**
 * Registre des opérations de rétention HORS base (LOT 05.6.4), FORMAT v2 CHAÎNÉ (LOT 05.6.7, arbitrage A2) : chaque ligne porte `v`, `seq` (numéro continu) et `prev`
 * (empreinte SHA-256 de la ligne précédente). Une ligne supprimée, modifiée ou réordonnée rompt la chaîne. La chaîne seule ne détecte PAS une réécriture complète recalculée :
 * c'est le rôle des points d'ancrage tenus ailleurs (`RegisterAnchors`) ; ce registre n'est donc jamais qualifié d'« inviolable ».
 *
 * L'écriture n'est pas atomique avec la transaction SQL (ajout APRÈS la validation) ; on n'ajoute jamais une ligne à une queue illisible (la chaîne ne s'étend pas en silence
 * au-dessus d'une corruption : l'écriture échoue et la réconciliation le signale).
 */
class PurgeRegisterFile
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private RetentionSettings $settings)
    {
    }

    /** @throws \RuntimeException si le fichier ne peut pas être écrit */
    public function append(PurgeRecord $record): void
    {
        $this->appendLine($record->toLine());
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws \RuntimeException
     */
    public function appendLine(array $payload): void
    {
        $path = $this->settings->registerPath();
        $directory = \dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Répertoire du registre de purge inaccessible.');
        }
        $handle = @fopen($path, 'a+b');
        if (false === $handle) {
            throw new \RuntimeException('Registre de purge inaccessible en écriture.');
        }
        try {
            if (!flock($handle, \LOCK_EX)) {
                throw new \RuntimeException('Registre de purge verrouillé.');
            }
            [$seq, $prev] = $this->tail($handle);
            $line = ['v' => 2, 'seq' => $seq + 1, 'prev' => $prev] + array_diff_key($payload, ['v' => 1, 'seq' => 1, 'prev' => 1]);
            if (false === fwrite($handle, json_encode($line, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)."\n") || !fflush($handle)) {
                throw new \RuntimeException('Écriture du registre de purge échouée.');
            }
            flock($handle, \LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Vérifie la chaîne d'un bout à l'autre.
     */
    public function verify(): ChainReport
    {
        $path = $this->settings->registerPath();
        if (!is_file($path)) {
            return new ChainReport(false, 0, 0, self::GENESIS, [], []);
        }
        $breaks = [];
        $hashes = [];
        $expected = 1;
        $previous = self::GENESIS;
        $lines = 0;
        $number = 0;
        foreach (file($path, \FILE_IGNORE_NEW_LINES) ?: [] as $raw) {
            ++$number;
            if ('' === $raw) {
                continue;
            }
            ++$lines;
            $line = json_decode($raw, true);
            if (!\is_array($line)) {
                $breaks[] = ['line' => $number, 'code' => ChainReport::BREAK_INVALID];
                $previous = hash('sha256', $raw);
                continue;
            }
            if (2 !== ($line['v'] ?? null) || !\is_int($line['seq'] ?? null) || !\is_string($line['prev'] ?? null)) {
                $breaks[] = ['line' => $number, 'code' => ChainReport::BREAK_UNCHAINED];
                $previous = hash('sha256', $raw);
                continue;
            }
            if ($line['seq'] !== $expected) {
                $breaks[] = ['line' => $number, 'code' => ChainReport::BREAK_SEQUENCE];
            }
            if ($line['prev'] !== $previous) {
                $breaks[] = ['line' => $number, 'code' => ChainReport::BREAK_PREVIOUS];
            }
            $previous = hash('sha256', $raw);
            $hashes[$line['seq']] = $previous;
            $expected = $line['seq'] + 1;
        }

        return new ChainReport(true, $lines, $expected - 1, $previous, $breaks, $hashes);
    }

    /**
     * Identifiants présents dans le fichier (une ligne illisible est ignorée et signalée par le rapport de réconciliation).
     *
     * @return array{ids: array<string, true>, invalidLines: int}
     */
    public function read(): array
    {
        $ids = [];
        $invalid = 0;
        foreach ($this->lines() as $line) {
            if (null === $line) {
                ++$invalid;
            } elseif (\is_string($line['id'] ?? null)) {
                $ids[$line['id']] = true;
            } else {
                ++$invalid;
            }
        }

        return ['ids' => $ids, 'invalidLines' => $invalid];
    }

    /**
     * Lignes décodées dans l'ordre du fichier (`null` pour une ligne illisible).
     *
     * @return list<array<string, mixed>|null>
     */
    public function lines(): array
    {
        $path = $this->settings->registerPath();
        if (!is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
            $decoded = json_decode($raw, true);
            $out[] = \is_array($decoded) ? $decoded : null;
        }

        return $out;
    }

    /**
     * Numéro de séquence et empreinte de la dernière ligne (valeurs de départ pour un fichier vide).
     *
     * @param resource $handle
     *
     * @return array{int, string}
     *
     * @throws \RuntimeException queue illisible ou non chaînée
     */
    private function tail($handle): array
    {
        $size = (int) (fstat($handle)['size'] ?? 0);
        if (0 === $size) {
            return [0, self::GENESIS];
        }
        $window = min($size, 65536);
        fseek($handle, $size - $window);
        $chunk = (string) fread($handle, $window);
        $last = '';
        foreach (explode("\n", rtrim($chunk, "\n")) as $candidate) {
            $last = $candidate;
        }
        $decoded = json_decode($last, true);
        if (!\is_array($decoded) || 2 !== ($decoded['v'] ?? null) || !\is_int($decoded['seq'] ?? null)) {
            throw new \RuntimeException('Queue du registre illisible ou non chaînée : aucune ligne n\'est ajoutée (incident à traiter, voir le runbook).');
        }

        return [$decoded['seq'], hash('sha256', $last)];
    }
}
