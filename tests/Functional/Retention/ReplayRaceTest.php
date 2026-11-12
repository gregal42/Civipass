<?php

declare(strict_types=1);

namespace App\Tests\Functional\Retention;

use App\Retention\Application\RetentionPurgeService;
use App\Tests\Support\ParallelPhp;
use App\Tests\Support\RestoreScenarioTestCase;

/**
 * Concurrence RÉELLE (processus PHP sur MySQL) du rejeu (LOT 05.6.7) : plusieurs rejeux simultanés de la même restauration ne suppriment jamais deux fois, n'écrivent jamais deux
 * lignes de rejeu pour un même dossier, et le verrou d'exécution refuse (sans mise en file) les exécutions concurrentes ; l'état final est identique à celui d'un rejeu unique.
 */
final class ReplayRaceTest extends RestoreScenarioTestCase
{
    public function testSimultaneousReplaysDeleteEverythingExactlyOnce(): void
    {
        $this->dueForPurge();
        $this->backupDatabase();
        static::getContainer()->get(RetentionPurgeService::class)->run(true, 100, null, null, $this->managed($this->admin));
        $this->em()->clear();
        $this->restoreDatabase();
        $hash = $this->report()->hash;
        $operator = $this->superAdmin->id()->toRfc4122();

        $results = [];
        foreach (ParallelPhp::run('replay-race.php', array_fill(0, 4, [$operator, self::REF, $hash])) as $raw) {
            $decoded = json_decode($raw['stdout'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded, $raw['stderr']);
            self::assertArrayNotHasKey('error', $decoded, json_encode($decoded, \JSON_THROW_ON_ERROR));
            $results[] = $decoded;
        }

        self::assertSame(2, array_sum(array_map(static fn (array $r): int => (int) ($r['dossiers'] ?? 0), $results)), 'chaque dossier est rejoué exactement une fois');
        self::assertSame(1, array_sum(array_map(static fn (array $r): int => (int) ($r['shared'] ?? 0), $results)));
        self::assertSame(0, array_sum(array_map(static fn (array $r): int => (int) ($r['failed'] ?? 0), $results)));
        foreach ($results as $r) {
            self::assertTrue($r['ok'] || \in_array($r['refused'] ?? '', ['REPLAY_ALREADY_RUNNING', 'REPORT_HASH_MISMATCH'], true), json_encode($r, \JSON_THROW_ON_ERROR));
        }
        foreach (['request_permit_request', 'request_permit_request_municipality', 'permit_permit'] as $table) {
            self::assertSame(0, $this->rows($table), $table);
        }
        // Une seule ligne de rejeu par dossier et par niveau, en plus des enregistrements d'origine réinscrits.
        self::assertSame(4, $this->rows('retention_purge_record', 'kind = \'DOSSIER\''));
        self::assertSame(2, $this->rows('retention_purge_record', 'kind = \'SHARED\''));
        self::assertSame(1, $this->rows('event_event'));
    }
}
