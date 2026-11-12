<?php

declare(strict_types=1);

namespace App\Tests\Functional\Retention;

use App\Retention\Application\EventDeletionService;
use App\Retention\Application\PurgeDisabled;
use App\Retention\Application\PurgeRegisterFile;
use App\Retention\Application\RegisterReconciliation;
use App\Retention\Application\RetentionNoticeService;
use App\Retention\Application\RetentionPurgeService;
use App\Retention\Application\RetentionSettings;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\RetentionScenarioTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Passage de purge, avis préalable, registre minimal et suppression de l'événement (LOT 05.6.4).
 */
final class RetentionRunTest extends RetentionScenarioTestCase
{
    private function runPurge(bool $execute = true): \App\Retention\Application\RetentionRunReport
    {
        $report = static::getContainer()->get(RetentionPurgeService::class)->run($execute, 100, null, null, $this->managed($this->admin));
        $this->em()->clear();

        return $report;
    }

    public function testTheNoticeIsSentOnceToTheMunicipalAdministratorsWithoutPersonalData(): void
    {
        $this->closeAndAge(13);
        $service = static::getContainer()->get(RetentionNoticeService::class);
        $simulated = $service->run(false);
        self::assertSame(0, $this->rows('retention_purge_record'), 'la simulation n\'écrit rien');
        self::assertGreaterThan(0, $simulated->done);

        $sent = $service->run(true);
        self::assertSame(2, $this->rows('retention_purge_record', 'kind = \'NOTICE\''), 'un avis par commune');
        self::assertSame(2, $sent->done);
        self::assertSame(0, $service->run(true)->done, 'un seul avis par commune et événement');
        $mails = static::getContainer()->get('mailer.message_logger_listener')->getEvents()->getMessages();
        self::assertNotSame([], $mails);
        foreach ($mails as $mail) {
            $text = $mail->toString();
            foreach (self::SENTINELS as $sentinel) {
                self::assertStringNotContainsStringIgnoringCase($sentinel, $text, 'aucune donnée personnelle dans l\'avis');
            }
        }
    }

    public function testTheRealPurgeIsRefusedWhileTheSwitchIsOffAndSimulationStaysPossible(): void
    {
        $_SERVER['RETENTION_PURGE_ENABLED'] = '0';
        $this->dueForPurge();
        $before = $this->snapshot();

        $this->expectException(PurgeDisabled::class);
        try {
            self::assertSame(2, static::getContainer()->get(RetentionPurgeService::class)->run(false)->done, 'la simulation reste possible');
            self::assertSame($before, $this->snapshot());
            static::getContainer()->get(RetentionPurgeService::class)->run(true);
        } finally {
            self::assertSame($before, $this->snapshot(), 'rien n\'est supprimé');
        }
    }

    public function testAFullRunPurgesEveryDueDossierThenTheSharedDataAndWritesTheRegister(): void
    {
        $this->dueForPurge();

        $simulated = $this->runPurge(false);
        self::assertSame(2, $simulated->candidates);
        self::assertSame(2, $this->rows('request_permit_request_municipality'));

        $report = $this->runPurge(true);
        self::assertSame(2, $report->done);
        self::assertSame(1, $report->sharedPurged);
        self::assertSame(0, $report->failed);
        self::assertSame(0, $this->rows('request_permit_request'));
        self::assertGreaterThan(0, $report->files);
        self::assertSame(0, $this->runPurge(true)->done, 'idempotent');

        $file = static::getContainer()->get(PurgeRegisterFile::class)->read();
        self::assertCount($this->rows('retention_purge_record'), $file['ids']);
        self::assertSame(0, $file['invalidLines']);
        foreach (static::getContainer()->get('doctrine.orm.entity_manager')->getConnection()->fetchAllAssociative('SELECT counts FROM retention_purge_record') as $row) {
            foreach (self::SENTINELS as $sentinel) {
                self::assertStringNotContainsStringIgnoringCase($sentinel, (string) $row['counts'], 'registre sans donnée personnelle');
            }
        }
        self::assertTrue(static::getContainer()->get(RegisterReconciliation::class)->check(false)->consistent());
    }

    public function testASqlFileDivergenceIsDetectedAndOnlyTheMissingFileLinesAreRepaired(): void
    {
        $this->dueForPurge();
        $this->runPurge(true);
        $reconciliation = static::getContainer()->get(RegisterReconciliation::class);
        $path = static::getContainer()->get(RetentionSettings::class)->registerPath();

        // Écriture du fichier perdue après la validation SQL.
        $lines = file($path, \FILE_IGNORE_NEW_LINES) ?: [];
        file_put_contents($path, implode("\n", \array_slice($lines, 1))."\n");
        $report = $reconciliation->check(false);
        self::assertCount(1, $report->missingInFile);
        self::assertFalse($report->consistent());
        self::assertSame(1, $reconciliation->check(true)->repaired);
        self::assertTrue($reconciliation->check(false)->consistent());

        // Ligne de fichier sans ligne SQL : signalée, jamais « réparée ».
        file_put_contents($path, '{"id":"0190a000-0000-7000-8000-000000000000","kind":"DOSSIER"}'."\n", \FILE_APPEND);
        $report = $reconciliation->check(true);
        self::assertSame(['0190a000-0000-7000-8000-000000000000'], $report->fileOnly);
        self::assertSame(0, $report->repaired);
        self::assertFalse($reconciliation->check(false)->consistent());
    }

    public function testCommandsSimulateByDefaultAndRefuseExecutionWhileDisabled(): void
    {
        $this->dueForPurge();
        $app = new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel);
        $purge = new CommandTester($app->find('app:retention:purge'));
        self::assertSame(0, $purge->execute([]));
        self::assertStringContainsString('SIMULATION', $purge->getDisplay());
        self::assertSame(2, $this->rows('request_permit_request_municipality'));
        self::assertSame(0, (new CommandTester($app->find('app:retention:register-check')))->execute([]), 'registre vide et fichier vide : cohérent');
    }

    public function testTheEventIsDeletedOnlyAfterEveryDossierIsPurgedByItsOrganizerAdministrator(): void
    {
        $this->designate($this->manager, $this->organizerId, $this->admin);
        $this->dueForPurge();
        $users = $this->rows('identity_user');
        $communes = $this->rows('municipality');
        $service = static::getContainer()->get(EventDeletionService::class);
        $reference = $this->event->publicReference()->value;

        self::assertArrayHasKey('dossiers', $service->blockers($this->managed($this->admin), $this->organizerId, $this->eventId()));
        try {
            $service->delete($this->managed($this->admin), $this->organizerId, $this->eventId(), $reference);
            self::fail('Des dossiers subsistent.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('dossiers', $e->getMessage());
        }
        $this->em()->clear();
        $this->runPurge(true);

        foreach ([[$this->partnerAdmin, $this->partnerId], [$this->manager, $this->organizerId], [$this->instructor, $this->organizerId], [$this->outsider, $this->outsiderCommuneId], [$this->partnerAdmin, $this->organizerId]] as [$user, $commune]) {
            try {
                $service->delete($this->managed($user), $commune, $this->eventId(), $reference);
                self::fail('Suppression par un utilisateur non habilité.');
            } catch (OperationNotAllowed) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            $service->delete($this->managed($this->admin), $this->organizerId, $this->eventId(), 'EVT-ERRONEE');
            self::fail('Référence erronée.');
        } catch (DomainRuleViolation) {
            $this->addToAssertionCount(1);
        }
        self::assertSame(1, $this->rows('event_event'));

        $service->delete($this->managed($this->admin), $this->organizerId, $this->eventId(), strtolower($reference));
        $this->em()->clear();
        self::assertSame(0, $this->rows('event_event'));
        self::assertSame(0, $this->rows('event_participation'));
        self::assertSame(0, $this->rows('event_zone'));
        self::assertSame(0, $this->rows('archive_manager_designation'));
        self::assertSame($users, $this->rows('identity_user'), 'comptes intacts');
        self::assertSame($communes, $this->rows('municipality'), 'communes intactes');
        self::assertSame(1, $this->rows('retention_purge_record', 'kind = \'EVENT_DELETED\''));
        self::assertSame(1, $this->rows('audit_log', 'action = \'RETENTION_EVENT_DELETED\''));
        self::assertTrue(static::getContainer()->get(RegisterReconciliation::class)->check(false)->consistent(), 'la suppression est aussi inscrite au registre hors base');
    }
}
