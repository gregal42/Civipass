<?php

declare(strict_types=1);

namespace App\Retention\Application;

use App\Retention\Domain\PurgeRecord;

/**
 * Registre des purges HORS BASE (LOT 05.6.4) : un fichier JSON Lines ajouté APRÈS la validation de la transaction SQL qu'il décrit. Il ne contient AUCUNE donnée
 * personnelle. LIMITES (consignées pour le LOT 05.6.7) : l'écriture du fichier n'est PAS atomique avec la base ; si elle échoue ou si le processus s'arrête entre le
 * commit et l'écriture, la ligne SQL existe sans ligne de fichier (divergence détectée et réparée par `app:retention:register-check --repair`) ; l'inverse (ligne de
 * fichier sans ligne SQL) ne survient qu'après une RESTAURATION de la base antérieure à la purge : c'est précisément le cas que le registre hors base sert à rejouer.
 */
final readonly class PurgeRegisterFile
{
    public function __construct(private RetentionSettings $settings)
    {
    }

    /** @throws \RuntimeException si le fichier ne peut pas être écrit */
    public function append(PurgeRecord $record): void
    {
        $this->appendLine($record->toLine());
    }

    /**
     * @param array<string, mixed> $line
     *
     * @throws \RuntimeException
     */
    public function appendLine(array $line): void
    {
        $path = $this->settings->registerPath();
        $directory = \dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Répertoire du registre de purge inaccessible.');
        }
        $handle = @fopen($path, 'ab');
        if (false === $handle) {
            throw new \RuntimeException('Registre de purge inaccessible en écriture.');
        }
        try {
            if (!flock($handle, \LOCK_EX)) {
                throw new \RuntimeException('Registre de purge verrouillé.');
            }
            if (false === fwrite($handle, json_encode($line, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)."\n") || !fflush($handle)) {
                throw new \RuntimeException('Écriture du registre de purge échouée.');
            }
            flock($handle, \LOCK_UN);
        } finally {
            fclose($handle);
        }
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
        $path = $this->settings->registerPath();
        if (!is_file($path)) {
            return ['ids' => [], 'invalidLines' => 0];
        }
        foreach (file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (\is_array($decoded) && \is_string($decoded['id'] ?? null)) {
                $ids[$decoded['id']] = true;
            } else {
                ++$invalid;
            }
        }

        return ['ids' => $ids, 'invalidLines' => $invalid];
    }
}
