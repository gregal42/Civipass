<?php

declare(strict_types=1);

namespace App\Tests\Functional\Retention;

use App\Tests\Support\ParallelPhp;
use App\Tests\Support\RetentionScenarioTestCase;

/**
 * Concurrence RÉELLE (processus PHP sur MySQL) de la purge (LOT 05.6.4) : deux purges du même dossier, purge simultanée des deux dossiers d'une demande, gel contre
 * purge. Invariants : une seule purge effective par dossier, données partagées supprimées exactement une fois (jamais perdues avant le dernier dossier), aucune
 * erreur technique, jamais un dossier purgé sous un gel actif.
 */
final class RetentionRaceTest extends RetentionScenarioTestCase
{
    /**
     * @param list<list<string>> $arguments
     *
     * @return list<array<string, mixed>>
     */
    private function race(array $arguments): array
    {
        $out = [];
        foreach (ParallelPhp::run('retention-race.php', $arguments) as $raw) {
            $decoded = json_decode($raw['stdout'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded, $raw['stderr']);
            self::assertTrue($decoded['ok'], json_encode($decoded, \JSON_THROW_ON_ERROR).$raw['stderr']);
            $out[] = $decoded;
        }

        return $out;
    }

    public function testTwoPurgesOfTheSameDossierPurgeItExactlyOnce(): void
    {
        $a = $this->dossierOf($this->organizerId);
        $this->dueForPurge();
        $admin = $this->admin->id()->toRfc4122();

        $results = $this->race([['purge', $admin, $a], ['purge', $admin, $a], ['purge', $admin, $a]]);

        $statuses = array_column($results, 'status');
        sort($statuses);
        self::assertSame(['ALREADY_DONE', 'ALREADY_DONE', 'PURGED'], $statuses);
        self::assertSame(1, $this->rows('retention_purge_record', 'kind = \'DOSSIER\''));
        self::assertSame(1, $this->rows('request_permit_request_municipality'), 'l\'autre dossier est intact');
        self::assertSame(1, $this->rows('request_permit_request'));
    }

    public function testPurgingBothDossiersSimultaneouslyDeletesTheSharedDataExactlyOnce(): void
    {
        $a = $this->dossierOf($this->organizerId);
        $b = $this->dossierOf($this->partnerId);
        $this->dueForPurge();
        $admin = $this->admin->id()->toRfc4122();

        $results = $this->race([['purge', $admin, $a], ['purge', $admin, $b]]);

        self::assertSame(['PURGED', 'PURGED'], array_column($results, 'status'));
        self::assertLessThanOrEqual(1, \count(array_filter(array_column($results, 'shared'))), 'jamais deux suppressions des données partagées');
        self::assertSame(2, $this->rows('retention_purge_record', 'kind = \'DOSSIER\''));
        self::assertSame(0, $this->rows('request_permit_request_municipality'));
        // Chaque processus a pu voir l'autre dossier encore présent : la demande est alors ORPHELINE (aucun dossier) et le prochain passage la reprend, une seule fois.
        $orphan = $this->rows('request_permit_request');
        self::assertSame(1 - \count(array_filter(array_column($results, 'shared'))), $orphan);
        $report = static::getContainer()->get(\App\Retention\Application\RetentionPurgeService::class)->run(true, 100, null, null, $this->managed($this->admin));
        $this->em()->clear();
        self::assertSame(0, $report->failed);
        self::assertSame(1, $this->rows('retention_purge_record', 'kind = \'SHARED\''), 'exactement une suppression des données partagées');
        foreach (['request_permit_request', 'request_permit_request_municipality', 'permit_permit', 'request_permit_request_attachment'] as $table) {
            self::assertSame(0, $this->rows($table), $table);
        }
    }

    public function testAHoldRacingAPurgeNeverLeavesAPurgedDossierUnderAnActiveHold(): void
    {
        $a = $this->dossierOf($this->organizerId);
        $this->dueForPurge();
        $admin = $this->admin->id()->toRfc4122();

        $raw = ParallelPhp::run('retention-race.php', [['purge', $admin, $a], ['place-hold', $admin, $a, $this->organizerId]]);
        $purge = json_decode($raw[0]['stdout'], true, 512, \JSON_THROW_ON_ERROR);
        $hold = json_decode($raw[1]['stdout'], true, 512, \JSON_THROW_ON_ERROR);

        self::assertTrue($purge['ok'], $raw[0]['stderr']);
        $dossier = $this->rows('request_permit_request_municipality', 'municipality_id = UNHEX(\''.str_replace('-', '', $this->organizerId).'\')');
        $activeHolds = $this->rows('archive_legal_hold', 'active_marker = 1');
        if (1 === $dossier) {
            self::assertSame('LEGAL_HOLD', $purge['reason'], 'le gel a gagné : la purge est refusée');
            self::assertTrue($hold['ok']);
            self::assertSame(1, $activeHolds);
        } else {
            self::assertSame('PURGED', $purge['status'], 'la purge a gagné');
            self::assertFalse($hold['ok'], 'le gel d\'un dossier purgé est refusé (404 uniforme)');
            self::assertSame(0, $activeHolds, 'aucun gel orphelin');
        }
    }
}
