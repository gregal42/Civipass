<?php

declare(strict_types=1);

namespace App\Tests\Functional\Event;

use App\Event\Application\ClosureCounters;
use App\Event\Application\ClosureStatisticsReader;
use App\Event\Application\Service\EventLifecycleService;
use App\Event\Domain\ClosureStatistics;
use App\Event\Domain\PublishedClosureStatistics;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Tests\Support\ClosedEventScenarioTestCase;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\UserBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * Bilan statistique de clôture (LOT 05.6.2) : compteurs exacts enregistrés une fois, dans la transaction de clôture ; publication seuillée à la lecture ;
 * permissions (organisatrice : global ; participante : sa ligne ; INSTRUCTOR : rien ; non participante : rien) ; aucune valeur exacte sous le seuil.
 */
final class ClosureStatisticsTest extends ClosedEventScenarioTestCase
{
    private User $partnerAdmin;
    private User $organizerManagerOnly;

    protected function setUp(): void
    {
        parent::setUp();
        $partner = $this->em()->find(Municipality::class, $this->partnerId) ?? self::fail('Commune absente.');
        $this->partnerAdmin = UserBuilder::aUser()->build();
        $this->organizerManagerOnly = $this->manager;
        $this->save($this->partnerAdmin, MembershipBuilder::aMembership($this->partnerAdmin, $partner)->withRoles(Role::MunicipalityAdmin)->build());
    }

    private function eventId(): string
    {
        return $this->event->id()->toRfc4122();
    }

    private function reader(): ClosureStatisticsReader
    {
        return static::getContainer()->get(ClosureStatisticsReader::class);
    }

    private function stored(): ClosureStatistics
    {
        $raw = $this->em()->getConnection()->fetchOne('SELECT closure_statistics FROM event_event');
        self::assertIsString($raw, 'compteurs enregistrés à la clôture');

        return ClosureStatistics::fromArray(json_decode($raw, true, 512, \JSON_THROW_ON_ERROR));
    }

    private function viewOf(User $user, string $municipalityId): ?\App\Event\Application\Read\ClosureStatisticsView
    {
        return $this->reader()->forViewer($this->managed($user), $municipalityId, $this->eventId());
    }

    // ------------------------------------------------------------------ enregistrement

    public function testTheClosureRecordsTheExactCountersOnceInTheSameTransaction(): void
    {
        $this->depositAndApprove(2);
        $this->close();

        $stored = $this->stored();
        $byId = [];
        foreach ($stored->municipalities as $row) {
            $byId[$row->municipalityId] = $row;
        }
        self::assertSame(3, $stored->requests, '1 dossier du scénario + 2');
        self::assertCount(2, $stored->municipalities, 'organisatrice et partenaire ; la commune invitée jamais acceptée n\'est pas comptée');
        foreach ([$this->organizerId, $this->partnerId] as $commune) {
            self::assertSame([3, 0, 0, 3], [$byId[$commune]->approved, $byId[$commune]->refused, $byId[$commune]->pending, $byId[$commune]->authorizations]);
        }
        $audit = json_decode((string) $this->em()->getConnection()->fetchOne('SELECT new_value FROM audit_log WHERE action = \'EVENT_CLOSED\''), true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($audit['closureStatistics']);
        self::assertStringNotContainsString('approved', (string) json_encode($audit), 'aucun compteur exact dans l\'audit');
        // Recomptage indépendant : rien n'a changé depuis la clôture.
        self::assertEquals($stored, static::getContainer()->get(ClosureCounters::class)->count($this->event->id()));
    }

    public function testTheStoredCountersContainNoPersonalDataAndPendingDossiersKeepTheirRealStatus(): void
    {
        $this->close();

        $raw = (string) $this->em()->getConnection()->fetchOne('SELECT closure_statistics FROM event_event');
        foreach (self::SENTINELS as $sentinel) {
            self::assertStringNotContainsStringIgnoringCase($sentinel, $raw);
        }
        self::assertStringNotContainsString($this->requestId, $raw);
        self::assertStringNotContainsString($this->attachmentId, $raw);
        self::assertSame(1, $this->stored()->requests);
    }

    public function testAFailureOfTheCounterComputationCancelsTheWholeClosure(): void
    {
        $c = static::getContainer();
        $failing = new class () implements ClosureCounters {
            public function count(Uuid $eventId): ClosureStatistics
            {
                throw new \RuntimeException('Échec injecté du calcul des compteurs.');
            }
        };
        $service = new EventLifecycleService(
            $c->get(\App\Event\Application\Support\EventTransactions::class),
            $c->get(\Psr\Clock\ClockInterface::class),
            $c->get(\App\Event\Application\EventResourceResolver::class),
            $c->get(\App\Event\Application\EventAccessPolicy::class),
            $c->get(\App\Event\Application\Support\EventLocker::class),
            $c->get(\App\Event\Domain\EventTypeRepository::class),
            $c->get(\App\Event\Domain\EventZoneRepository::class),
            $c->get(\App\Event\Application\Support\EventAuditRecorder::class),
            $c->get(\App\Event\Application\ClosureNotificationNeutralizer::class),
            $c->get(\App\Event\Application\ClosureInvitationRevoker::class),
            $failing,
        );
        $connection = $this->em()->getConnection();
        $pending = (int) $connection->fetchOne('SELECT COUNT(*) FROM notification_outbox WHERE status = \'PENDING\'');
        self::assertGreaterThan(0, $pending);

        try {
            $service->close($this->managed($this->admin), $this->eventId());
            self::fail('La clôture doit échouer avec le calcul.');
        } catch (\RuntimeException) {
            $this->em()->clear();
        }

        self::assertSame('ACTIVE', $connection->fetchOne('SELECT status FROM event_event'), 'aucun état mixte : l\'événement n\'est pas clos');
        self::assertNull($connection->fetchOne('SELECT closure_statistics FROM event_event'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_log WHERE action = \'EVENT_CLOSED\''));
        self::assertSame($pending, (int) $connection->fetchOne('SELECT COUNT(*) FROM notification_outbox WHERE status = \'PENDING\''), 'les notifications n\'ont pas été annulées');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM event_participation WHERE status = \'CLOSED\''));
    }

    public function testASecondClosureNeverRecalculatesTheCounters(): void
    {
        $this->close();
        $before = (string) $this->em()->getConnection()->fetchOne('SELECT closure_statistics FROM event_event');

        try {
            static::getContainer()->get(EventLifecycleService::class)->close($this->managed($this->admin), $this->eventId());
            self::fail('Une seconde clôture est refusée.');
        } catch (\Throwable) {
            $this->em()->clear();
        }

        self::assertSame($before, (string) $this->em()->getConnection()->fetchOne('SELECT closure_statistics FROM event_event'), 'compteurs inchangés');
    }

    // ------------------------------------------------------------------ permissions et publication

    public function testTheOrganizerManagementRolesSeeTheGlobalBilanButAnInstructorNeverDoes(): void
    {
        $this->close();

        self::assertNotNull($this->viewOf($this->admin, $this->organizerId)?->global, 'MUNICIPALITY_ADMIN de l\'organisatrice');
        self::assertNotNull($this->viewOf($this->organizerManagerOnly, $this->organizerId)?->global, 'EVENT_MANAGER de l\'organisatrice, sans désignation');
        self::assertNull($this->viewOf($this->instructor, $this->organizerId), 'INSTRUCTOR : aucun accès par défaut');
        self::assertNull($this->viewOf($this->partnerInstructor, $this->partnerId), 'INSTRUCTOR d\'une commune participante : aucun accès');
    }

    public function testAParticipatingMunicipalitySeesOnlyItsOwnRowNeverTheGlobalNorTheOthers(): void
    {
        $this->close();

        $view = $this->viewOf($this->partnerAdmin, $this->partnerId);

        self::assertNotNull($view);
        self::assertNull($view->global, 'jamais le global');
        self::assertNotNull($view->own);
        self::assertSame(PublishedClosureStatistics::SCOPE_COMMUNE, $view->own->scope);
        self::assertNull($view->own->communes, 'jamais la ventilation par commune');
        self::assertSame([], $view->communeNames);
    }

    public function testNoOneSeesTheBilanOfAnotherMunicipalityEvenWithForgedIdentifiers(): void
    {
        $this->close();
        $outsider = $this->managed($this->outsider);

        // Utilisatrice d'une commune sans lien avec l'événement, avec ses droits d'instructeur (aucun rôle de gestion) puis en rôle de gestion.
        self::assertNull($this->reader()->forViewer($outsider, $this->outsiderCommuneId, $this->eventId()));
        self::assertNull($this->reader()->forViewer($outsider, $this->organizerId, $this->eventId()), 'commune d\'autrui : refus uniforme (null)');
        self::assertNull($this->reader()->forViewer($this->managed($this->admin), $this->partnerId, $this->eventId()), 'administrateur de l\'organisatrice, mais pas membre de la commune partenaire');
        self::assertNull($this->reader()->forViewer($this->managed($this->admin), $this->organizerId, Uuid::v7()->toRfc4122()), 'événement inexistant');
        self::assertNull($this->reader()->forViewer($this->managed($this->admin), $this->organizerId, 'pas-un-uuid'));
        self::assertNull($this->reader()->forViewer($this->managed($this->admin), 'pas-un-uuid', $this->eventId()));
    }

    public function testThereIsNoBilanBeforeTheClosureNorForAnEventClosedBeforeTheMigration(): void
    {
        self::assertNull($this->viewOf($this->admin, $this->organizerId), 'événement actif : aucun bilan');

        $this->close();
        self::assertNotNull($this->viewOf($this->admin, $this->organizerId));
        $this->sql('UPDATE event_event SET closure_statistics = NULL');

        self::assertNull($this->viewOf($this->admin, $this->organizerId), 'événement clos avant la migration : « bilan non disponible », jamais recalculé');
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT closure_statistics FROM event_event'));
    }

    public function testSmallCountsAreMaskedAndNeverShownAsExactValues(): void
    {
        $this->close(); // 1 dossier par commune : sous le seuil de 5

        $global = $this->viewOf($this->admin, $this->organizerId)->global ?? self::fail('Bilan attendu.');

        self::assertNull($global->dossiers, 'total masqué : un effectif commune est sous le seuil');
        self::assertNull($global->communes);
        self::assertNull($global->byStatus);
        self::assertContains('communes', $global->masked);
        $own = $this->viewOf($this->partnerAdmin, $this->partnerId)->own ?? self::fail('Bilan attendu.');
        self::assertNull($own->dossiers);
        self::assertNull($own->byStatus, 'groupe masqué tout entier');
    }

    public function testEnoughDossiersAreRevealedPerMunicipalityOnlyWithTheCoordinationRight(): void
    {
        $this->depositAndApprove(5);
        $this->close(); // 6 dossiers par commune

        $organizer = $this->viewOf($this->admin, $this->organizerId) ?? self::fail('Bilan attendu.');
        $global = $organizer->global ?? self::fail('Global attendu.');
        self::assertSame(12, $global->dossiers);
        self::assertNull($global->byStatus, 'jamais le global par statut dans un événement multicommunal');
        self::assertSame([6, 6], array_column($global->communes ?? [], 'dossiers'));
        self::assertCount(2, $organizer->communeNames);
        self::assertSame(['approved' => 6, 'refused' => 0, 'pending' => 0, 'authorizations' => 6], $organizer->own?->byStatus, 'sa propre répartition');

        $partner = $this->viewOf($this->partnerAdmin, $this->partnerId) ?? self::fail('Bilan attendu.');
        self::assertNull($partner->global);
        self::assertSame(6, $partner->own?->dossiers);
    }

    // ------------------------------------------------------------------ page de l'événement

    private function page(User $user, string $municipalityId): string
    {
        $this->as($user);
        $this->client->request('GET', '/communes/'.$municipalityId.'/evenements/'.$this->eventId());
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    public function testThePageShowsAMaskedBilanToTheOrganizerWithoutAnyExactValue(): void
    {
        $this->close();

        $html = $this->page($this->admin, $this->organizerId);

        self::assertStringContainsString('Bilan de clôture', $html);
        self::assertStringContainsString('Non communiqué', $html);
        self::assertStringContainsString('Ventilation par commune non communiquée', $html);
        self::assertStringNotContainsString('Approuvés', $html, 'aucune répartition sous le seuil');
    }

    public function testThePageShowsThePublishedValuesAndNothingForAnInstructorOrBeforeTheClosure(): void
    {
        $before = $this->page($this->admin, $this->organizerId);
        self::assertStringNotContainsString('Bilan de clôture', $before);

        $this->depositAndApprove(5);
        $this->close();

        $html = $this->page($this->admin, $this->organizerId);
        self::assertStringContainsString('Bilan de clôture', $html);
        self::assertStringContainsString('Dossiers communaux', $html);
        self::assertStringContainsString('Dossiers par commune', $html);
        self::assertStringContainsString('Votre commune', $html);

        $partnerHtml = $this->page($this->partnerAdmin, $this->partnerId);
        self::assertStringContainsString('Dossiers de votre commune', $partnerHtml);
        self::assertStringNotContainsString('Dossiers par commune', $partnerHtml);
        self::assertStringNotContainsString('Ensemble de l’événement', $partnerHtml);

        self::assertStringNotContainsString('Bilan de clôture', $this->page($this->instructor, $this->organizerId), 'INSTRUCTOR : aucun bilan');
    }

    public function testThePageNeverGivesAccessToTheArchivedDossiersThroughTheBilan(): void
    {
        $this->close();

        $html = $this->page($this->admin, $this->organizerId);

        self::assertStringNotContainsString($this->requestId, $html);
        self::assertStringNotContainsString('/demandes/'.$this->requestId, $html, 'aucun lien vers un dossier');
        self::assertStringNotContainsString('/archives', $html, 'aucun lien vers le futur module d\'archives');
        foreach (self::SENTINELS as $sentinel) {
            self::assertStringNotContainsStringIgnoringCase($sentinel, $html);
        }
    }
}
