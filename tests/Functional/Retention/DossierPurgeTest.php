<?php

declare(strict_types=1);

namespace App\Tests\Functional\Retention;

use App\Archive\Application\LegalHoldService;
use App\Retention\Application\DossierPurgeService;
use App\Retention\Application\PurgeOutcome;
use App\Tests\Support\ConfigurableStepObserver;
use App\Tests\Support\RetentionScenarioTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Purge communale indépendante (LOT 05.6.4, DM-120) : éligibilité démontrée, ordres A puis B et B puis A, gel actif, simulation sans écriture, idempotence, retour
 * arrière et reprise à chaque étape, absence d'orphelins, compteurs exacts remplacés par l'instantané publié.
 */
final class DossierPurgeTest extends RetentionScenarioTestCase
{
    public function testADossierIsNeverPurgedWhenEligibilityIsNotDemonstrated(): void
    {
        $a = $this->dossierOf($this->organizerId);
        $before = $this->snapshot();
        self::assertSame(PurgeOutcome::NOT_ELIGIBLE, $this->purge($a)->status, 'événement non clos');
        $this->closeAndAge(1);
        self::assertSame(DossierPurgeService::RETENTION_NOT_REACHED, $this->purge($a)->reason);
        $this->sql('UPDATE event_event SET closed_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 13 MONTH)');
        self::assertSame(DossierPurgeService::NO_NOTICE, $this->purge($a)->reason, 'sans avis préalable');
        $this->sql('UPDATE event_event SET closed_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 13 MONTH)');
        static::getContainer()->get(\App\Retention\Application\RetentionNoticeService::class)->run(true);
        $this->em()->clear();
        self::assertSame(DossierPurgeService::NOTICE_TOO_RECENT, $this->purge($a)->reason);

        $after = $this->snapshot();
        foreach (['audit_log', 'retention_purge_record', 'event_event', 'notification_outbox'] as $moving) {
            unset($before[$moving], $after[$moving]);
        }
        self::assertSame($before, $after, 'aucune suppression');
    }

    public function testAnActiveHoldForbidsThePurgeOfItsDossierOnly(): void
    {
        $a = $this->dossierOf($this->organizerId);
        $b = $this->dossierOf($this->partnerId);
        $this->close();
        static::getContainer()->get(LegalHoldService::class)->place($this->managed($this->admin), $this->organizerId, $a, 'LITIGE', null);
        $this->em()->clear();
        $this->dueForPurge();

        self::assertSame(DossierPurgeService::LEGAL_HOLD, $this->purge($a)->reason);
        self::assertSame(PurgeOutcome::PURGED, $this->purge($b)->status, 'le gel de A ne bloque pas B');
        self::assertSame(1, $this->rows('request_permit_request'), 'demande partagée conservée tant que A doit l\'être');
        self::assertSame(1, $this->rows('request_permit_request_municipality'));
        self::assertSame(1, $this->rows('permit_permit'));
        self::assertGreaterThan(0, $this->rows('request_permit_request_attachment'));
    }

    /** @return array<string, array{bool}> */
    public static function orders(): array
    {
        return ['A puis B' => [true], 'B puis A' => [false]];
    }

    #[DataProvider('orders')]
    public function testBothPurgeOrdersKeepSharedDataUntilTheLastDossier(bool $organizerFirst): void
    {
        $a = $this->dossierOf($this->organizerId);
        $b = $this->dossierOf($this->partnerId);
        $this->dueForPurge();
        [$first, $second] = $organizerFirst ? [$a, $b] : [$b, $a];

        self::assertSame(PurgeOutcome::PURGED, $this->purge($first)->status);
        self::assertSame(1, $this->rows('request_permit_request_municipality'));
        self::assertSame(1, $this->rows('request_permit_request'), 'données partagées conservées');
        self::assertSame(1, $this->rows('permit_permit'));
        self::assertSame(1, $this->rows('permit_authorization'), 'seule l\'autorisation de la commune purgée a disparu');
        self::assertSame(1, $this->rows('request_permit_request_decision'));
        self::assertSame(0, $this->rows('permit_permit', 'first_decision_id IS NOT NULL AND first_decision_id NOT IN (SELECT id FROM request_permit_request_decision)'), 'aucune référence orpheline');
        self::assertSame(1, json_decode((string) $this->em()->getConnection()->fetchOne('SELECT closure_statistics FROM event_event'), true)['v'], 'compteurs exacts conservés tant qu\'un dossier subsiste');

        $last = $this->purge($second);
        self::assertSame(PurgeOutcome::PURGED, $last->status);
        self::assertTrue($last->sharedDue, 'plus aucun dossier : les données partagées sont dues');
        self::assertSame(PurgeOutcome::PURGED, $this->purgeShared((string) $last->requestId)->status);
        foreach (['request_permit_request', 'request_permit_request_municipality', 'request_permit_request_decision', 'request_permit_request_attachment', 'request_permit_request_zone', 'request_idempotency_key', 'permit_permit', 'permit_authorization', 'permit_revision', 'notification_outbox', 'notification_secret', 'archive_consultation', 'archive_legal_hold', 'archive_manager_designation'] as $table) {
            self::assertSame(0, $this->rows($table), $table.' vide après le dernier dossier');
        }
        self::assertSame(2, json_decode((string) $this->em()->getConnection()->fetchOne('SELECT closure_statistics FROM event_event'), true)['v'], 'instantané publié (v2) : plus aucun compteur exact');
        self::assertSame(2, $this->rows('retention_purge_record', 'kind = \'DOSSIER\''));
        self::assertSame(1, $this->rows('retention_purge_record', 'kind = \'SHARED\''));
        self::assertSame(1, $this->rows('event_event'), 'l\'événement subsiste jusqu\'à sa suppression explicite');
        self::assertSame(0, $this->rows('audit_log', 'subject_type IN (\'PERMIT_REQUEST\', \'PERMIT\')'));
    }

    public function testThePublishedStatisticsOutliveThePurgeWithoutAnyExactCounter(): void
    {
        $this->dueForPurge();
        $reader = static::getContainer()->get(\App\Event\Application\ClosureStatisticsReader::class);
        $before = $reader->forViewer($this->managed($this->admin), $this->organizerId, $this->eventId());
        self::assertNotNull($before);

        $first = $this->purge($this->dossierOf($this->organizerId));
        $last = $this->purge($this->dossierOf($this->partnerId));
        $this->purgeShared((string) $last->requestId);

        $event = $this->em()->find(\App\Event\Domain\Event::class, $this->event->id());
        self::assertNull($event->closureStatistics(), 'plus aucun compteur exact');
        self::assertNotNull($event->closureSummary());
        $after = $reader->forViewer($this->managed($this->admin), $this->organizerId, $this->eventId());
        self::assertNotNull($after, 'le bilan reste servi, mêmes droits');
        self::assertEquals($before->own, $after->own, 'valeurs publiées inchangées (même seuil)');
        self::assertNull($reader->forViewer($this->managed($this->outsider), $this->organizerId, $this->eventId()), 'toujours aucun lecteur non habilité');
        self::assertSame(PurgeOutcome::PURGED, $first->status);
    }

    public function testSimulationWritesNothingAndExecutionIsIdempotent(): void
    {
        $a = $this->dossierOf($this->organizerId);
        $this->dueForPurge();
        $before = $this->snapshot();

        $simulated = $this->purge($a, false);
        self::assertSame(PurgeOutcome::SIMULATED, $simulated->status);
        self::assertSame($before, $this->snapshot());

        self::assertSame(PurgeOutcome::PURGED, $this->purge($a)->status);
        $after = $this->snapshot();
        self::assertSame(PurgeOutcome::ALREADY_DONE, $this->purge($a)->status);
        self::assertSame($after, $this->snapshot(), 'un second passage ne change rien');
    }

    public function testAFailureAtEveryStepRollsBackAndTheRerunCompletes(): void
    {
        $a = $this->dossierOf($this->organizerId);
        $this->dueForPurge();
        $before = $this->snapshot();

        $failures = 0;
        for ($n = 1; $n <= 80; ++$n) {
            ConfigurableStepObserver::reset();
            ConfigurableStepObserver::$failAfter = $n;
            try {
                $outcome = $this->purge($a);
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('Panne injectée', $e->getMessage());
                // Le gestionnaire d'entités est fermé par la panne (comportement voulu) : nouveau noyau, même base.
                self::ensureKernelShutdown();
                self::bootKernel();
                self::assertSame($before, $this->snapshot(), 'retour arrière complet après l\'étape '.$n);
                ++$failures;
                continue;
            }
            self::assertSame(PurgeOutcome::PURGED, $outcome->status, 'la reprise aboutit une fois les pannes épuisées');
            break;
        }
        self::assertGreaterThan(10, $failures, 'chaque étape a été interrompue');
        self::assertSame(1, $this->rows('request_permit_request_municipality'));
        self::assertSame(1, $this->rows('request_permit_request'));
    }
}
