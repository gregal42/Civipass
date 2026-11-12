<?php

declare(strict_types=1);

namespace App\Retention\Application;

use App\Retention\Domain\AnchorReport;
use App\Retention\Domain\ChainReport;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Points d'ancrage du registre (LOT 05.6.7, arbitrage A2) : à intervalles réguliers, l'empreinte de TÊTE de la chaîne (et son numéro de séquence) est inscrite dans un fichier
 * SÉPARÉ, lui-même chaîné, avec des repères MONOTONES de la base (dernier audit, comptes, communes, demandes de droits : ils ne diminuent jamais en fonctionnement normal).
 * Ils servent à détecter (1) une RÉÉCRITURE COMPLÈTE de la chaîne (les empreintes recalculées ne correspondent plus aux points d'ancrage), (2) une troncature de la queue,
 * (3) une restauration de base antérieure (repères en régression).
 *
 * L'ancrage n'est INDÉPENDANT que si le fichier d'ancrage (ou sa copie, courriel, journal distant) est hors de portée de qui peut réécrire le registre : l'application ne peut pas
 * le garantir. Elle émet le point sur trois canaux (fichier, journal, courriel d'exploitation, voir la commande) et sait vérifier une copie externe fournie par l'opérateur.
 * Tant que l'indépendance n'est pas CONFIRMÉE en exploitation, le registre n'est pas qualifié d'inviolable.
 */
class RegisterAnchors
{
    public function __construct(
        private readonly RetentionSettings $settings,
        private readonly PurgeRegisterFile $register,
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Inscrit un point d'ancrage si, et seulement si, la chaîne et les points existants sont sains (on n'ancre jamais une incohérence).
     *
     * @return array{ok: bool, reason: string|null, line: array<string, mixed>|null}
     *
     * @throws \RuntimeException fichier d'ancrage inaccessible
     */
    public function emit(): array
    {
        $chain = $this->register->verify();
        if (!$chain->intact()) {
            return ['ok' => false, 'reason' => 'CHAIN_BROKEN', 'line' => null];
        }
        $existing = $this->verify($chain);
        $problems = array_values(array_diff($existing->problems, [AnchorReport::FILE_ABSENT]));
        if ([] !== $problems) {
            return ['ok' => false, 'reason' => implode(',', $problems), 'line' => null];
        }
        $path = $this->settings->anchorPath();
        $directory = \dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Répertoire des points d\'ancrage inaccessible.');
        }
        $handle = @fopen($path, 'a+b');
        if (false === $handle) {
            throw new \RuntimeException('Fichier des points d\'ancrage inaccessible en écriture.');
        }
        try {
            if (!flock($handle, \LOCK_EX)) {
                throw new \RuntimeException('Fichier des points d\'ancrage verrouillé.');
            }
            $previous = PurgeRegisterFile::GENESIS;
            $size = (int) (fstat($handle)['size'] ?? 0);
            if ($size > 0) {
                fseek($handle, max(0, $size - 65536));
                $lines = explode("\n", rtrim((string) fread($handle, 65536), "\n"));
                $previous = hash('sha256', (string) end($lines));
            }
            $line = [
                'v' => 1,
                'at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'seq' => $chain->lastSeq,
                'head' => $chain->headHash,
                'prev' => $previous,
            ] + $this->markers();
            if (false === fwrite($handle, json_encode($line, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)."\n") || !fflush($handle)) {
                throw new \RuntimeException('Écriture du point d\'ancrage échouée.');
            }
            flock($handle, \LOCK_UN);
        } finally {
            fclose($handle);
        }

        return ['ok' => true, 'reason' => null, 'line' => $line];
    }

    /**
     * Repères monotones de la base à l'instant présent.
     *
     * @return array<string, mixed>
     */
    public function markers(): array
    {
        $audit = $this->connection->fetchOne('SELECT MAX(occurred_at) FROM audit_log');

        return [
            'auditLast' => \is_string($audit) ? (new \DateTimeImmutable($audit, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z') : null,
            'users' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM identity_user'),
            'municipalities' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM municipality'),
            'rightsRequests' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM rights_request'),
        ];
    }

    /**
     * Vérifie les points d'ancrage du fichier configuré (ou d'une COPIE EXTERNE fournie) contre la chaîne du registre.
     */
    public function verify(ChainReport $chain, ?string $externalPath = null): AnchorReport
    {
        $path = $externalPath ?? $this->settings->anchorPath();
        if ('' === $path || !is_file($path)) {
            return new AnchorReport(0, [null === $externalPath ? AnchorReport::FILE_ABSENT : AnchorReport::EXTERNAL_ABSENT], null);
        }
        $problems = [];
        $previous = PurgeRegisterFile::GENESIS;
        $lastSeq = 0;
        $last = null;
        $count = 0;
        foreach (file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
            $line = json_decode($raw, true);
            if (!\is_array($line) || !\is_int($line['seq'] ?? null) || !\is_string($line['head'] ?? null) || !\is_string($line['prev'] ?? null)) {
                $problems[] = AnchorReport::INVALID;
                $previous = hash('sha256', $raw);
                continue;
            }
            ++$count;
            if ($line['prev'] !== $previous || $line['seq'] < $lastSeq) {
                $problems[] = AnchorReport::MISMATCH;
            }
            $previous = hash('sha256', $raw);
            $lastSeq = max($lastSeq, $line['seq']);
            $last = $line;
            if ($line['seq'] > $chain->lastSeq) {
                $problems[] = AnchorReport::CHAIN_TRUNCATED;
            } elseif (0 === $line['seq'] ? PurgeRegisterFile::GENESIS !== $line['head'] : ($chain->hashes[$line['seq']] ?? null) !== $line['head']) {
                $problems[] = AnchorReport::MISMATCH;
            }
        }

        return new AnchorReport($count, array_values(array_unique($problems)), $last);
    }
}
