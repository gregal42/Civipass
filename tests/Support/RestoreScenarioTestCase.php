<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Retention\Application\DossierPurgeService;
use App\Retention\Application\PurgeRegisterFile;
use App\Retention\Application\RegisterAnchors;
use App\Retention\Application\RestoreChecker;
use App\Retention\Application\RetentionReplayService;
use App\Retention\Application\SharedPurgeService;
use App\Retention\Domain\ReplayReport;
use App\Retention\Domain\RestoreItem;
use App\Retention\Domain\RestoreReport;

/**
 * Scénario partagé des tests de restauration (LOT 05.6.7) : une RESTAURATION RÉELLE de l'état SQL (copie de toutes les tables, puis remise en place), pendant que les fichiers
 * du registre, des points d'ancrage et du stockage restent dans leur état POSTÉRIEUR (ils ne sont jamais restaurés) : c'est exactement la situation d'une base restaurée
 * depuis une sauvegarde antérieure à des purges. Aucun rejeu destructif sur des données existantes : tout se passe dans la base de TEST.
 */
abstract class RestoreScenarioTestCase extends RightsScenarioTestCase
{
    protected const REF = 'INSTRUCTION-2026-007';

    protected function tearDown(): void
    {
        try {
            $this->dropSnapshots();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    /** Copie de l'état SQL courant (la « sauvegarde »). */
    protected function backupDatabase(): void
    {
        $c = $this->em()->getConnection();
        $this->dropSnapshots();
        foreach ($this->tables() as $table) {
            $c->executeStatement('CREATE TABLE `zz_snap_'.$table.'` LIKE `'.$table.'`');
            $c->executeStatement('INSERT INTO `zz_snap_'.$table.'` SELECT * FROM `'.$table.'`');
        }
    }

    /** Restauration de l'état SQL copié ; registre, ancrages et stockage restent POSTÉRIEURS. */
    protected function restoreDatabase(): void
    {
        $c = $this->em()->getConnection();
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->tables() as $table) {
            $c->executeStatement('DELETE FROM `'.$table.'`');
            $c->executeStatement('INSERT INTO `'.$table.'` SELECT * FROM `zz_snap_'.$table.'`');
        }
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $this->em()->clear();
    }

    private function dropSnapshots(): void
    {
        $c = $this->em()->getConnection();
        foreach ($c->fetchFirstColumn('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE \'zz\_snap\_%\'') as $table) {
            $c->executeStatement('DROP TABLE `'.$table.'`');
        }
    }

    /** @return list<string> */
    private function tables(): array
    {
        return array_map('strval', $this->em()->getConnection()->fetchFirstColumn('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\' AND table_name NOT LIKE \'zz\_snap\_%\' ORDER BY table_name'));
    }

    /** Purge d'un dossier par le service existant ET inscription au registre externe (comme le fait l'orchestrateur), dans l'ordre voulu. */
    protected function purgeRecorded(string $dossierId): ?string
    {
        $outcome = static::getContainer()->get(DossierPurgeService::class)->purge($dossierId, true, $this->managed($this->admin));
        $this->em()->clear();
        self::assertSame('PURGED', $outcome->status, (string) $outcome->reason);
        static::getContainer()->get(PurgeRegisterFile::class)->append($outcome->record ?? self::fail('Enregistrement absent.'));

        return $outcome->sharedDue ? $outcome->requestId : null;
    }

    protected function purgeSharedRecorded(string $requestId): void
    {
        $outcome = static::getContainer()->get(SharedPurgeService::class)->purge($requestId, true, $this->managed($this->admin));
        $this->em()->clear();
        self::assertSame('PURGED', $outcome->status);
        static::getContainer()->get(PurgeRegisterFile::class)->append($outcome->record ?? self::fail('Enregistrement absent.'));
    }

    protected function anchor(): void
    {
        $result = static::getContainer()->get(RegisterAnchors::class)->emit();
        self::assertTrue($result['ok'], (string) $result['reason']);
    }

    protected function report(?string $external = null): RestoreReport
    {
        return static::getContainer()->get(RestoreChecker::class)->check($external);
    }

    protected function replay(bool $execute, ?string $hash = null, string $ref = self::REF, ?\App\Identity\Domain\User $as = null, ?string $external = null): ReplayReport
    {
        $r = static::getContainer()->get(RetentionReplayService::class)->run($this->managed($as ?? $this->superAdmin), $ref, $hash, $execute, $external);
        $this->em()->clear();

        return $r;
    }

    /** @return list<string> codes de qualification d'un code de constat, triés */
    protected function qualifications(RestoreReport $report, string $code): array
    {
        $q = array_map(static fn (RestoreItem $i): string => $i->qualification, $report->byCode($code));
        sort($q);

        return $q;
    }

    /** @return array<string, int> effectifs des tables touchées par un rejeu */
    protected function counts(): array
    {
        $out = [];
        foreach (['request_permit_request', 'request_permit_request_municipality', 'request_permit_request_decision', 'permit_permit', 'permit_authorization', 'event_event', 'event_participation', 'municipality_membership', 'identity_user', 'archive_legal_hold'] as $table) {
            $out[$table] = $this->rows($table);
        }

        return $out;
    }

    /** Réécrit le registre en RECALCULANT toute la chaîne après `$mutate` (simule une réécriture complète par quelqu'un qui peut écrire le fichier). */
    protected function rewriteChain(callable $mutate): void
    {
        $path = static::getContainer()->get(\App\Retention\Application\RetentionSettings::class)->registerPath();
        $lines = [];
        foreach (file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
            $lines[] = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        }
        $lines = $mutate($lines);
        $previous = PurgeRegisterFile::GENESIS;
        $out = '';
        foreach (array_values($lines) as $i => $line) {
            $line['seq'] = $i + 1;
            $line['prev'] = $previous;
            $raw = json_encode($line, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
            $previous = hash('sha256', $raw);
            $out .= $raw."\n";
        }
        file_put_contents($path, $out);
    }
}
