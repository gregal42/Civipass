<?php

declare(strict_types=1);

namespace App\Tests\Functional\Retention;

use App\Archive\Application\LegalHoldService;
use App\Ops\Application\LossFindings;
use App\Ops\Application\MaintenanceMode;
use App\Ops\Application\ReleaseRefused;
use App\Ops\Application\ReleaseService;
use App\Retention\Application\PurgeDisabled;
use App\Retention\Application\ReplayRefused;
use App\Retention\Application\RetentionPurgeService;
use App\Retention\Domain\RestoreItem;
use App\Rights\Domain\RightsRequestKind;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\ConfigurableStepObserver;
use App\Tests\Support\RestoreScenarioTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Restauration d'une sauvegarde ANTÉRIEURE à des purges, puis rejeu (LOT 05.6.7) : l'état SQL est réellement remis en arrière, le registre externe, les ancrages et le stockage
 * restent postérieurs. Détection des données réintroduites et des pertes, rejeu approuvé, simulé d'abord, idempotent, reprenable, multicommunal, sans modification de droits.
 */
final class RestoreReplayTest extends RestoreScenarioTestCase
{
    /** Événement clos, avis envoyés, SAUVEGARDE, puis purge complète (registre et ancrage postérieurs), puis RESTAURATION de la sauvegarde. */
    private function purgedThenRestored(): void
    {
        $this->dueForPurge();
        $this->backupDatabase();
        $report = static::getContainer()->get(RetentionPurgeService::class)->run(true, 100, null, null, $this->managed($this->admin));
        $this->em()->clear();
        self::assertSame(2, $report->done);
        self::assertSame(1, $report->sharedPurged);
        $this->restoreDatabase();
    }

    // ------------------------------------------------------------------ détection

    public function testARestoreBeforeAPurgeReintroducesTheDossiersAndTheSharedDataAndIsDetected(): void
    {
        $this->purgedThenRestored();
        self::assertSame(2, $this->rows('request_permit_request_municipality'), 'les dossiers sont revenus');

        $report = $this->report();

        self::assertTrue($report->proofSound(), implode(',', $report->proofProblems));
        self::assertSame(['REPLAYABLE', 'REPLAYABLE'], $this->qualifications($report, RestoreItem::REINTRODUCED_DOSSIER));
        self::assertSame(['REPLAYABLE'], $this->qualifications($report, RestoreItem::REINTRODUCED_SHARED));
        self::assertNotSame([], $report->byCode(RestoreItem::POSSIBLE_DATA_LOSS), 'perte possible postérieure à la sauvegarde');
        self::assertNotSame([], $report->byCode(RestoreItem::FILES_EXPECTED_MISSING), 'les fichiers purgés ne reviennent pas');
        self::assertSame([], $report->byCode(RestoreItem::FILES_MISSING));
        self::assertSame([], $report->byCode(RestoreItem::PURGED_BUT_PRESENT));
        self::assertNotSame([], $report->blocking());
        foreach ($report->items as $item) {
            foreach (self::SENTINELS as $sentinel) {
                self::assertStringNotContainsStringIgnoringCase($sentinel, json_encode($item->toArray(), \JSON_THROW_ON_ERROR), 'aucune donnée personnelle dans le rapport');
            }
        }
    }

    public function testAnOlderSqlRegisterMakesTheLostRecordsFileOnlyAndAdoptableOnceTheDataIsGone(): void
    {
        $this->purgedThenRestored();
        $this->replay(true, $this->report()->hash);
        $report = $this->report();

        self::assertSame([], $report->byCode(RestoreItem::REINTRODUCED_DOSSIER));
        self::assertSame([], $report->byCode(RestoreItem::FILE_ONLY), 'les enregistrements d\'origine ont été réinscrits en base avec leur identifiant');
        self::assertSame(4, $this->rows('retention_purge_record', 'kind = \'DOSSIER\''), 'enregistrements d\'origine réinscrits et lignes de rejeu');
        self::assertSame(2, $this->rows('retention_purge_record', 'kind = \'SHARED\''));
        $reconciliation = static::getContainer()->get(\App\Retention\Application\RegisterReconciliation::class)->check();
        self::assertSame([], $reconciliation->fileOnly);
        self::assertSame([], $reconciliation->missingInFile);
        self::assertSame([], $reconciliation->chainBreaks);
    }

    public function testTheCheckIsReadOnly(): void
    {
        $this->purgedThenRestored();
        $before = [$this->counts(), $this->rows('retention_purge_record'), $this->rows('audit_log'), file_get_contents(static::getContainer()->get(\App\Retention\Application\RetentionSettings::class)->registerPath())];

        $this->report();
        $this->report();

        self::assertSame($before, [$this->counts(), $this->rows('retention_purge_record'), $this->rows('audit_log'), file_get_contents(static::getContainer()->get(\App\Retention\Application\RetentionSettings::class)->registerPath())]);
    }

    // ------------------------------------------------------------------ refus du rejeu

    public function testTheReplayIsSimulatedByDefaultAndWritesNothing(): void
    {
        $this->purgedThenRestored();
        $before = [$this->counts(), $this->rows('retention_purge_record'), $this->rows('audit_log')];

        $r = $this->replay(false);

        self::assertFalse($r->executed);
        self::assertSame([2, 1], [$r->plannedDossiers, $r->plannedShared]);
        self::assertSame($before, [$this->counts(), $this->rows('retention_purge_record'), $this->rows('audit_log')]);
    }

    public function testTheExecutionIsRefusedWithoutApprovalOperatorOrThePresentedReport(): void
    {
        $this->purgedThenRestored();
        $hash = $this->report()->hash;
        $before = $this->counts();

        foreach ([['', $hash, 'APPROVAL_REFERENCE_REQUIRED'], ['x', $hash, 'APPROVAL_REFERENCE_REQUIRED'], [self::REF, null, 'REPORT_HASH_MISMATCH'], [self::REF, '', 'REPORT_HASH_MISMATCH'], [self::REF, str_repeat('a', 64), 'REPORT_HASH_MISMATCH']] as [$ref, $h, $code]) {
            try {
                $this->replay(true, $h, $ref);
                self::fail('Rejeu exécuté sans approbation ou sans le rapport présenté.');
            } catch (ReplayRefused $e) {
                self::assertSame($code, $e->reasonCode);
            }
        }
        foreach ([$this->admin, $this->manager, $this->partnerAdmin, $this->instructor] as $user) {
            try {
                $this->replay(true, $hash, self::REF, $user);
                self::fail('Rejeu par un opérateur non habilité.');
            } catch (OperationNotAllowed) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame($before, $this->counts(), 'rien n\'a été modifié');
    }

    public function testAReportThatChangedSinceItWasPresentedIsRefused(): void
    {
        $this->purgedThenRestored();
        $hash = $this->report()->hash;
        static::getContainer()->get(LegalHoldService::class)->place($this->managed($this->admin), $this->organizerId, $this->dossierOf($this->organizerId), 'LITIGE', null);
        $this->em()->clear();

        $this->expectException(ReplayRefused::class);
        $this->expectExceptionMessage('empreinte');
        $this->replay(true, $hash);
    }

    public function testTheExecutionNeedsThePurgeSwitch(): void
    {
        $this->purgedThenRestored();
        $hash = $this->report()->hash;
        self::ensureKernelShutdown();
        $_SERVER['RETENTION_PURGE_ENABLED'] = '0';
        self::bootKernel();
        $before = $this->counts();

        try {
            $this->replay(true, $hash);
            self::fail('Rejeu malgré l\'interrupteur désactivé.');
        } catch (PurgeDisabled) {
            $this->addToAssertionCount(1);
        }
        self::assertSame($before, $this->counts());
        self::assertSame(2, $this->replay(false)->plannedDossiers, 'la simulation reste possible');
    }

    // ------------------------------------------------------------------ rejeu

    public function testTheApprovedReplayDeletesTheReintroducedDossiersThenTheSharedDataIdempotently(): void
    {
        $this->purgedThenRestored();
        $hash = $this->report()->hash;

        $r = $this->replay(true, $hash);

        self::assertSame([2, 1, 0, 0], [$r->dossiersReplayed, $r->sharedReplayed, $r->failed, $r->blocked]);
        foreach (['request_permit_request', 'request_permit_request_municipality', 'request_permit_request_decision', 'permit_permit', 'notification_outbox', 'request_permit_request_attachment'] as $table) {
            self::assertSame(0, $this->rows($table), $table);
        }
        self::assertSame(1, $this->rows('event_event'), 'l\'événement n\'est jamais supprimé par le rejeu');
        $audit = $this->em()->getConnection()->fetchAllAssociative('SELECT new_value FROM audit_log WHERE action = \'RETENTION_REPLAY_EXECUTED\'');
        self::assertCount(1, $audit);
        self::assertStringContainsString(self::REF, (string) $audit[0]['new_value']);
        self::assertStringContainsString($hash, (string) $audit[0]['new_value']);

        // Idempotence : un second rejeu, sur le nouveau rapport, ne fait plus rien.
        $second = $this->replay(true, $this->report()->hash);
        self::assertSame([0, 0, 0], [$second->dossiersReplayed, $second->sharedReplayed, $second->failed]);
        self::assertSame(2, $this->rows('audit_log', 'action = \'RETENTION_REPLAY_EXECUTED\''), 'chaque exécution est tracée, y compris celle qui ne fait plus rien');
    }

    /** @return array<string, array{bool}> */
    public static function orders(): array
    {
        return ['A puis B' => [true], 'B puis A' => [false]];
    }

    #[DataProvider('orders')]
    public function testBothPurgeOrdersAreReplayedToTheSameFinalState(bool $organizerFirst): void
    {
        $this->dueForPurge();
        $a = $this->dossierOf($this->organizerId);
        $b = $this->dossierOf($this->partnerId);
        $this->backupDatabase();
        [$first, $second] = $organizerFirst ? [$a, $b] : [$b, $a];
        self::assertNull($this->purgeRecorded($first), 'l\'autre dossier justifie encore les données partagées');
        $request = $this->purgeRecorded($second);
        self::assertNotNull($request);
        $this->purgeSharedRecorded((string) $request);
        $this->anchor();
        $this->restoreDatabase();

        $r = $this->replay(true, $this->report()->hash);

        self::assertSame([2, 1], [$r->dossiersReplayed, $r->sharedReplayed]);
        self::assertSame(0, $this->rows('request_permit_request'));
        self::assertSame(0, $this->rows('request_permit_request_municipality'));
    }

    public function testAHoldThatAppearsAgainBlocksOnlyItsDossierAndKeepsTheSharedData(): void
    {
        $this->dueForPurge();
        $a = $this->dossierOf($this->organizerId);
        $holds = static::getContainer()->get(LegalHoldService::class);
        $holds->place($this->managed($this->admin), $this->organizerId, $a, 'LITIGE', null);
        $this->em()->clear();
        $this->backupDatabase();
        $holds->lift($this->managed($this->admin), $this->organizerId, $a, 'Levé avant la purge');
        $this->em()->clear();
        static::getContainer()->get(RetentionPurgeService::class)->run(true, 100, null, null, $this->managed($this->admin));
        $this->em()->clear();
        $this->restoreDatabase();

        $report = $this->report();
        self::assertSame(['BLOCKED_HOLD', 'REPLAYABLE'], $this->qualifications($report, RestoreItem::REINTRODUCED_DOSSIER));
        self::assertSame(['BLOCKED_DOSSIERS_REMAIN'], $this->qualifications($report, RestoreItem::REINTRODUCED_SHARED), 'le dossier gelé retient la donnée partagée');
    }

    public function testTheSharedDataStaysWhileAnotherCommunesDossierIsNotReplayable(): void
    {
        $this->dueForPurge();
        $a = $this->dossierOf($this->organizerId);
        $holds = static::getContainer()->get(LegalHoldService::class);
        $holds->place($this->managed($this->admin), $this->organizerId, $a, 'LITIGE', null);
        $this->em()->clear();
        // Seul le dossier de la commune partenaire est purgé (celui de l'organisatrice est gelé) : le registre ne porte aucune purge des données partagées.
        $this->backupDatabase();
        $b = $this->dossierOf($this->partnerId);
        self::assertNull($this->purgeRecorded($b));
        $this->anchor();
        $this->restoreDatabase();

        $report = $this->report();
        self::assertSame(['REPLAYABLE'], $this->qualifications($report, RestoreItem::REINTRODUCED_DOSSIER));
        self::assertSame([], $report->byCode(RestoreItem::REINTRODUCED_SHARED));

        $r = $this->replay(true, $report->hash);
        self::assertSame(1, $r->dossiersReplayed);
        self::assertSame(1, $this->rows('request_permit_request'), 'donnée partagée conservée : le dossier gelé de l\'organisatrice en dépend');
        self::assertSame(1, $this->rows('request_permit_request_municipality'));
        self::assertSame(1, $this->rows('archive_legal_hold', 'active_marker = 1'), 'le gel est intact');
        self::assertSame(1, $this->rows('permit_permit'));
    }

    public function testAnEventReopenedByTheRestoreBlocksTheReplayUntilItIsClosedAgain(): void
    {
        // Sauvegarde prise AVANT la clôture : l'état restauré a un événement actif.
        $this->backupDatabase();
        $this->dueForPurge();
        static::getContainer()->get(RetentionPurgeService::class)->run(true, 100, null, null, $this->managed($this->admin));
        $this->em()->clear();
        $this->restoreDatabase();
        self::assertSame('ACTIVE', $this->em()->getConnection()->fetchOne('SELECT status FROM event_event'));

        $report = $this->report();

        self::assertSame(['BLOCKED_EVENT_NOT_CLOSED', 'BLOCKED_EVENT_NOT_CLOSED'], $this->qualifications($report, RestoreItem::REINTRODUCED_DOSSIER));
        self::assertSame(['BLOCKED_DOSSIERS_REMAIN'], $this->qualifications($report, RestoreItem::REINTRODUCED_SHARED));
        self::assertNotSame([], $report->byCode(RestoreItem::FILE_ONLY), 'les avis perdus par la restauration sont réinscriptibles');
        $r = $this->replay(true, $report->hash);
        self::assertSame([0, 0], [$r->dossiersReplayed, $r->sharedReplayed], 'rien n\'est rejoué sur un événement actif');
        self::assertSame(2, $this->rows('request_permit_request_municipality'));
        self::assertSame('ACTIVE', $this->em()->getConnection()->fetchOne('SELECT status FROM event_event'), 'aucun statut modifié');
    }

    public function testAnOpenRightsRequestBlocksTheReplayButTheOriginOfAnErasureDoesNot(): void
    {
        $_SERVER['RIGHTS_ERASURE_EXECUTION_ENABLED'] = '1';
        $this->dueForPurge();
        // Une demande d'ACCÈS ouverte existe dans l'état sauvegardé (elle a été close ensuite) : elle bloque.
        $access = $this->verified(RightsRequestKind::Access);
        $this->backupDatabase();
        $this->svc()->decide($this->managed($this->admin), $this->organizerId, $access, 'REFUSED', 'OTHER_GROUNDS', false);
        $this->svc()->close($this->managed($this->admin), $this->organizerId, $access);
        $this->em()->clear();
        static::getContainer()->get(RetentionPurgeService::class)->run(true, 100, null, null, $this->managed($this->admin));
        $this->em()->clear();
        $this->restoreDatabase();

        $report = $this->report();
        self::assertSame(['BLOCKED_RIGHTS_OPEN', 'REPLAYABLE'], $this->qualifications($report, RestoreItem::REINTRODUCED_DOSSIER), 'seul le dossier visé par la demande ouverte est bloqué');
        $r = $this->replay(true, $report->hash);
        self::assertSame(1, $r->dossiersReplayed);
        self::assertSame(1, $this->rows('request_permit_request_municipality'));
        self::assertSame('IDENTITY_VERIFIED', $this->requestRow($access)['status'], 'la demande de droits n\'est pas touchée');
    }

    public function testAnErasureRequestThatOrderedTheOriginalPurgeIsRecognisedAndMarkedExecuted(): void
    {
        $_SERVER['RIGHTS_ERASURE_EXECUTION_ENABLED'] = '1';
        $this->close();
        $id = $this->verified(RightsRequestKind::Erasure);
        $this->svc()->decide($this->managed($this->admin), $this->organizerId, $id, 'GRANTED', null, true);
        $this->em()->clear();
        $this->backupDatabase();
        static::getContainer()->get(\App\Rights\Application\RightsErasureService::class)->execute($this->managed($this->admin), $this->organizerId, $id);
        $this->em()->clear();
        self::assertNotNull($this->requestRow($id)['executed_at']);
        $this->anchor();
        $this->restoreDatabase();
        self::assertNull($this->requestRow($id)['executed_at'], 'état restauré : effacement non exécuté');

        $report = $this->report();
        self::assertSame(['REPLAYABLE'], $this->qualifications($report, RestoreItem::REINTRODUCED_DOSSIER), 'la demande d\'effacement d\'origine n\'est pas un blocage');
        $r = $this->replay(true, $report->hash);

        self::assertSame(1, $r->dossiersReplayed);
        self::assertNotNull($this->requestRow($id)['executed_at'], 'la demande d\'effacement est de nouveau marquée exécutée');
        self::assertSame(0, $this->rows('rights_request_dossier'));
        self::assertSame(1, $this->rows('request_permit_request_municipality'), 'le dossier de l\'autre commune est intact');
    }

    // ------------------------------------------------------------------ interruption, reprise, aucun droit modifié

    public function testAnInterruptionRollsBackTheCurrentDossierAndTheResumeCompletes(): void
    {
        $this->purgedThenRestored();
        $hash = $this->report()->hash;
        $before = $this->counts();

        ConfigurableStepObserver::$failAfter = 4;
        $r = $this->replay(true, $hash);

        self::assertSame(1, $r->failed);
        self::assertTrue($r->interrupted, 'le gestionnaire d\'entités fermé arrête proprement le rejeu');
        self::assertSame($before, $this->counts(), 'retour arrière complet du dossier en cours');

        self::ensureKernelShutdown();
        self::bootKernel();
        $resumed = $this->replay(true, $this->report()->hash);
        self::assertSame([2, 1, 0], [$resumed->dossiersReplayed, $resumed->sharedReplayed, $resumed->failed]);
        self::assertSame(0, $this->rows('request_permit_request'));
    }

    public function testTheReplayNeverChangesRightsMembershipsOrAuthorisationsOfWhatItDoesNotDelete(): void
    {
        $this->dueForPurge();
        $a = $this->dossierOf($this->organizerId);
        $holds = static::getContainer()->get(LegalHoldService::class);
        $holds->place($this->managed($this->admin), $this->organizerId, $a, 'LITIGE', null);
        $this->em()->clear();
        $this->backupDatabase();
        $b = $this->dossierOf($this->partnerId);
        $this->purgeRecorded($b);
        $this->anchor();
        $this->restoreDatabase();
        $c = $this->em()->getConnection();
        $rights = fn (): array => [
            $c->fetchAllAssociative('SELECT * FROM municipality_membership ORDER BY id'),
            $c->fetchAllAssociative('SELECT * FROM identity_user ORDER BY id'),
            $c->fetchAllAssociative('SELECT a.* FROM permit_authorization a JOIN request_permit_request_municipality rm ON rm.municipality_id = a.municipality_id WHERE rm.id = UNHEX(:d) ORDER BY a.id', ['d' => str_replace('-', '', $this->dossierOf($this->organizerId))]),
            $c->fetchAllAssociative('SELECT * FROM archive_legal_hold ORDER BY id'),
            $c->fetchAllAssociative('SELECT status, closed_at FROM event_event'),
        ];
        $before = $rights();

        $this->replay(true, $this->report()->hash);

        self::assertSame($before, $rights(), 'aucun droit, rôle, autorisation, gel ni statut modifié');
    }

    // ------------------------------------------------------------------ remise en service

    public function testTheMaintenanceIsOnlyReleasedWhenNoBlockingAnomalyRemainsAndTheLossIsAcknowledged(): void
    {
        $this->purgedThenRestored();
        $maintenance = static::getContainer()->get(MaintenanceMode::class);
        $maintenance->enable('restauration test');
        $release = static::getContainer()->get(ReleaseService::class);
        $operator = $this->managed($this->superAdmin);

        try {
            $release->release($operator, null);
            self::fail('Levée de la maintenance avec des dossiers réintroduits.');
        } catch (ReleaseRefused $e) {
            self::assertContains(RestoreItem::REINTRODUCED_DOSSIER, array_map(static fn (RestoreItem $i): string => $i->code, $e->items));
        }
        self::assertTrue($maintenance->active());

        $this->replay(true, $this->report()->hash);
        try {
            $release->release($operator, null);
            self::fail('Levée sans reconnaissance de la perte possible.');
        } catch (ReleaseRefused $e) {
            self::assertSame([RestoreItem::POSSIBLE_DATA_LOSS], array_values(array_unique(array_map(static fn (RestoreItem $i): string => $i->code, $e->items))), 'la perte constatée reste consignée même si le rejeu l\'a effacée du contrôle');
        }
        self::assertNotSame([], static::getContainer()->get(LossFindings::class)->all());
        try {
            $release->release($operator, 'x');
            self::fail('Référence de reconnaissance invalide acceptée.');
        } catch (ReleaseRefused) {
            $this->addToAssertionCount(1);
        }
        foreach ([$this->admin, $this->manager] as $other) {
            try {
                $release->release($this->managed($other), 'RECONNAISSANCE-2026-01');
                self::fail('Levée par un non super-administrateur.');
            } catch (OperationNotAllowed) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertGreaterThan(0, $release->release($operator, 'RECONNAISSANCE-2026-01'));
        self::assertFalse($maintenance->active());
        self::assertSame([], static::getContainer()->get(LossFindings::class)->all());
        self::assertSame(1, $this->rows('audit_log', 'action = \'OPS_MAINTENANCE_RELEASED\''));
    }
}
