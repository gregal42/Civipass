<?php

declare(strict_types=1);

namespace App\Tests\Functional\Retention;

use App\Retention\Application\PurgeRegisterFile;
use App\Retention\Application\RegisterAnchors;
use App\Retention\Application\ReplayRefused;
use App\Retention\Application\RetentionSettings;
use App\Retention\Domain\PurgeRecord;
use App\Retention\Domain\PurgeRecordKind;
use App\Retention\Domain\RestoreItem;
use App\Tests\Support\RestoreScenarioTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Preuve externe du registre (LOT 05.6.7, A2) : chaîne, ancrage indépendant, réécriture complète, troncature, registre ou ancrage absents. Sans preuve saine, AUCUN rejeu. Les
 * constats de perte, d'incident et d'événement réintroduit sont qualifiés sans jamais être effacés. L'ancrage local seul ne protège pas contre qui réécrit AUSSI le fichier
 * d'ancrage : seule une copie EXTERNE le détecte (c'est pourquoi le registre n'est pas qualifié d'inviolable).
 */
final class RegisterProofTest extends RestoreScenarioTestCase
{
    private function settings(): RetentionSettings
    {
        return static::getContainer()->get(RetentionSettings::class);
    }

    /** Restauration d'une sauvegarde antérieure à une purge complète ; retourne la copie EXTERNE des points d'ancrage. */
    private function restoredWithExternalAnchors(): string
    {
        $this->dueForPurge();
        $this->backupDatabase();
        $a = $this->dossierOf($this->organizerId);
        $b = $this->dossierOf($this->partnerId);
        $this->purgeRecorded($a);
        $request = $this->purgeRecorded($b);
        $this->purgeSharedRecorded((string) $request);
        $this->anchor();
        $external = sys_get_temp_dir().'/civipass-external-anchors-'.bin2hex(random_bytes(4)).'.jsonl';
        copy($this->settings()->anchorPath(), $external);
        $this->restoreDatabase();

        return $external;
    }

    private function assertRefusedUntouched(string $code, ?string $external = null): void
    {
        $before = $this->counts();
        try {
            $this->replay(true, $this->report($external)->hash, external: $external);
            self::fail('Rejeu malgré une preuve externe non saine.');
        } catch (ReplayRefused $e) {
            self::assertSame('PROOF_NOT_SOUND', $e->reasonCode);
            self::assertStringContainsString($code, $e->getMessage());
        }
        self::assertSame($before, $this->counts(), 'rien n\'a été supprimé');
    }

    public function testASoundProofAllowsTheReplayEvenWithAnExternalCopy(): void
    {
        $external = $this->restoredWithExternalAnchors();
        self::assertTrue($this->report($external)->proofSound());

        self::assertSame(2, $this->replay(true, $this->report($external)->hash, external: $external)->dossiersReplayed);
        @unlink($external);
    }

    public function testAModifiedLineBreaksTheChainAndRefusesTheReplay(): void
    {
        $this->restoredWithExternalAnchors();
        $path = $this->settings()->registerPath();
        $lines = file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
        $lines[2] = str_replace('"kind":"DOSSIER"', '"kind":"NOTICE"', $lines[2]);
        file_put_contents($path, implode("\n", $lines)."\n");

        $report = $this->report();
        self::assertFalse($report->proofSound());
        self::assertNotSame([], $report->byCode(RestoreItem::PROOF_PROBLEM));
        $this->assertRefusedUntouched('PREVIOUS_HASH_MISMATCH');
    }

    public function testADeletedLineIsDetectedAndRefusesTheReplay(): void
    {
        $this->restoredWithExternalAnchors();
        $path = $this->settings()->registerPath();
        $lines = file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
        unset($lines[1]);
        file_put_contents($path, implode("\n", array_values($lines))."\n");

        $this->assertRefusedUntouched('SEQUENCE_BREAK');
    }

    public function testACompleteRewriteWithRecomputedHashesIsCaughtByTheAnchors(): void
    {
        $this->restoredWithExternalAnchors();
        // L'auteur recalcule TOUTE la chaîne après avoir effacé la trace d'une purge : la chaîne est intacte, les points d'ancrage ne concordent plus.
        $this->rewriteChain(static function (array $lines): array {
            foreach ($lines as $i => $line) {
                if ('DOSSIER' === $line['kind']) {
                    unset($lines[$i]);
                    break;
                }
            }

            return array_values($lines);
        });

        self::assertTrue(static::getContainer()->get(PurgeRegisterFile::class)->verify()->intact(), 'la chaîne recalculée est cohérente par elle-même');
        $problems = $this->report()->proofProblems;
        self::assertTrue([] !== array_intersect(['ANCHOR_MISMATCH', 'CHAIN_SHORTER_THAN_ANCHOR'], $problems), implode(',', $problems));
        $this->assertRefusedUntouched('ANCHOR');
    }

    public function testRewritingTheLocalAnchorsTooIsOnlyCaughtByTheExternalCopy(): void
    {
        $external = $this->restoredWithExternalAnchors();
        $this->rewriteChain(static function (array $lines): array {
            foreach ($lines as $i => $line) {
                if ('DOSSIER' === $line['kind']) {
                    $lines[$i]['eventReference'] = 'REECRIT';
                    break;
                }
            }

            return $lines;
        });
        // L'auteur réécrit AUSSI le fichier d'ancrage local, de façon cohérente avec la nouvelle chaîne.
        @unlink($this->settings()->anchorPath());
        $this->anchor();

        self::assertTrue($this->report()->proofSound(), 'localement tout paraît sain : l\'ancrage local seul ne suffit pas');
        $external_report = $this->report($external);
        self::assertFalse($external_report->proofSound());
        $this->assertRefusedUntouched('EXTERNAL:ANCHOR_MISMATCH', $external);
        @unlink($external);
    }

    public function testATruncatedTailIsDetectedByTheAnchors(): void
    {
        $this->restoredWithExternalAnchors();
        $path = $this->settings()->registerPath();
        $lines = file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
        file_put_contents($path, implode("\n", \array_slice($lines, 0, -2))."\n");

        $this->assertRefusedUntouched('CHAIN_SHORTER_THAN_ANCHOR');
    }

    public function testAnAbsentAnchorFileOrRegisterIsAMissingProof(): void
    {
        $this->restoredWithExternalAnchors();
        $anchors = $this->settings()->anchorPath();
        $copy = (string) file_get_contents($anchors);
        unlink($anchors);
        $this->assertRefusedUntouched('ANCHOR_FILE_ABSENT');

        file_put_contents($anchors, $copy);
        $register = $this->settings()->registerPath();
        $saved = (string) file_get_contents($register);
        unlink($register);
        $this->assertRefusedUntouched('REGISTER_ABSENT');
        file_put_contents($register, $saved);

        $this->assertRefusedUntouched('EXTERNAL_ANCHOR_FILE_ABSENT', '/nonexistent/anchors.jsonl');
    }

    public function testNoAnchorIsEmittedOverABrokenChainOrAnInconsistentAnchor(): void
    {
        $this->restoredWithExternalAnchors();
        $anchors = static::getContainer()->get(RegisterAnchors::class);
        self::assertTrue($anchors->emit()['ok']);
        $before = (string) file_get_contents($this->settings()->anchorPath());

        $path = $this->settings()->registerPath();
        $lines = file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
        $lines[1] = str_replace('NOTICE', 'DOSSIER', $lines[1]);
        file_put_contents($path, implode("\n", $lines)."\n");

        $result = $anchors->emit();
        self::assertFalse($result['ok']);
        self::assertSame('CHAIN_BROKEN', $result['reason']);
        self::assertSame($before, (string) file_get_contents($this->settings()->anchorPath()), 'jamais d\'ancrage d\'une incohérence');
    }

    public function testEachAnchorIsChainedAndCarriesMonotonicMarkersWithoutPersonalData(): void
    {
        $this->dueForPurge();
        $this->anchor();
        $this->anchor();
        $lines = array_map(static fn (string $l): array => json_decode($l, true), file($this->settings()->anchorPath(), \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: []);

        self::assertCount(2, $lines);
        self::assertSame(PurgeRegisterFile::GENESIS, $lines[0]['prev']);
        self::assertSame(hash('sha256', (string) file($this->settings()->anchorPath(), \FILE_IGNORE_NEW_LINES)[0]), $lines[1]['prev']);
        foreach (['auditLast', 'users', 'municipalities', 'rightsRequests', 'head', 'seq', 'at'] as $key) {
            self::assertArrayHasKey($key, $lines[0]);
        }
        $raw = (string) file_get_contents($this->settings()->anchorPath());
        foreach (self::SENTINELS as $sentinel) {
            self::assertStringNotContainsStringIgnoringCase($sentinel, $raw);
        }
    }

    // ------------------------------------------------------------------ qualification des anomalies

    public function testAFreshSystemHasNothingToProveAndNoAnomaly(): void
    {
        $report = $this->report();

        self::assertTrue($report->proofSound());
        self::assertSame([], $report->items);
    }

    public function testAnEventOrCommuneCitedByTheRegisterButAbsentFromTheDatabaseIsALoss(): void
    {
        $this->dueForPurge();
        $record = static fn (Uuid $event, ?Uuid $municipality): PurgeRecord => new PurgeRecord(PurgeRecordKind::Notice, $event, 'EVT-PERDU', $municipality, null, null, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), null, null, null, 0, 1, null);
        static::getContainer()->get(PurgeRegisterFile::class)->append($record(Uuid::v7(), Uuid::v7()));

        $report = $this->report();

        self::assertCount(1, $report->byCode(RestoreItem::EVENT_MISSING));
        self::assertCount(1, $report->byCode(RestoreItem::MUNICIPALITY_MISSING));
        self::assertSame(['ACKNOWLEDGE_REQUIRED'], $this->qualifications($report, RestoreItem::EVENT_MISSING));
    }

    public function testRegressingMonotonicMarkersAreReportedAsPossibleLosses(): void
    {
        $this->dueForPurge();
        $this->anchor();
        $c = $this->em()->getConnection();
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $c->executeStatement('DELETE FROM identity_user WHERE id = UNHEX(:u)', ['u' => str_replace('-', '', $this->outsider->id()->toRfc4122())]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $details = array_map(static fn (RestoreItem $i): string => $i->detail, $this->report()->byCode(RestoreItem::POSSIBLE_DATA_LOSS));

        self::assertNotSame([], array_filter($details, static fn (string $d): bool => str_contains($d, 'comptes')));
    }

    public function testAPurgedRecordWhoseDossierIsStillPresentIsAnIncidentNeverReplayed(): void
    {
        $this->dueForPurge();
        $a = $this->dossierOf($this->organizerId);
        $row = $this->em()->getConnection()->fetchAssociative('SELECT rm.request_id, rm.municipality_id FROM request_permit_request_municipality rm WHERE rm.id = UNHEX(:d)', ['d' => str_replace('-', '', $a)]);
        $record = new PurgeRecord(PurgeRecordKind::Dossier, $this->event->id(), 'EVT', Uuid::fromBinary((string) $row['municipality_id']), Uuid::fromString($a), Uuid::fromBinary((string) $row['request_id']), new \DateTimeImmutable('now', new \DateTimeZone('UTC')), null, null, [], 0, null, null);
        $this->em()->persist($record);
        $this->em()->flush();
        static::getContainer()->get(PurgeRegisterFile::class)->append($record);
        $this->anchor();

        $report = $this->report();

        self::assertSame(['INCIDENT'], $this->qualifications($report, RestoreItem::PURGED_BUT_PRESENT));
        self::assertSame([], $report->replayable(RestoreItem::REINTRODUCED_DOSSIER));
        self::assertSame(2, $this->rows('request_permit_request_municipality'));
    }

    public function testAnEventDeletionIsNeverReplayedAutomatically(): void
    {
        $this->dueForPurge();
        static::getContainer()->get(PurgeRegisterFile::class)->append(new PurgeRecord(PurgeRecordKind::EventDeleted, $this->event->id(), 'EVT', Uuid::fromString($this->organizerId), null, null, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), null, null, [], 0, null, null));
        $this->anchor();

        $report = $this->report();
        self::assertSame(['MANUAL'], $this->qualifications($report, RestoreItem::REINTRODUCED_EVENT));
        $this->replay(true, $report->hash);

        self::assertSame(1, $this->rows('event_event'), 'le rejeu ne supprime jamais un événement');
        self::assertSame(2, $this->rows('request_permit_request_municipality'));
    }

    public function testAMissingDossierWithoutAnyRecordBreaksTheClosureCounts(): void
    {
        $this->close();
        $c = $this->em()->getConnection();
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $c->executeStatement('DELETE FROM request_permit_request_municipality WHERE municipality_id = UNHEX(:m)', ['m' => str_replace('-', '', $this->partnerId)]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $this->em()->clear();

        $mismatch = $this->report()->byCode(RestoreItem::COUNT_MISMATCH);

        self::assertCount(1, $mismatch);
        self::assertSame('INCIDENT', $mismatch[0]->qualification);
    }

    public function testAMissingFileOfALiveDossierIsALossNotAnExpectedAbsence(): void
    {
        $key = (string) $this->em()->getConnection()->fetchOne('SELECT storage_key FROM request_permit_request_attachment LIMIT 1');
        static::getContainer()->get(\App\Shared\Application\FileStorageInterface::class)->delete($key);

        $report = $this->report();

        self::assertCount(1, $report->byCode(RestoreItem::FILES_MISSING));
        self::assertSame([], $report->byCode(RestoreItem::FILES_EXPECTED_MISSING));
    }
}
