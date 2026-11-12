<?php

declare(strict_types=1);

namespace App\Tests\Functional\Archive;

use App\Tests\Support\ArchiveScenarioTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Accès aux archives (LOT 05.6.3, DM-120, option B) : matrice rôle × commune × événement × dossier × état, identifiants forgés, lecture seule, motif et journal.
 */
final class ArchiveAccessTest extends ArchiveScenarioTestCase
{
    private function get(string $url): int
    {
        $this->client->request('GET', $url);
        if (500 === $this->client->getResponse()->getStatusCode()) {
            self::fail('500 sur '.$url.' : '.rawurldecode((string) $this->client->getResponse()->headers->get('X-Debug-Exception')).' @ '.rawurldecode((string) $this->client->getResponse()->headers->get('X-Debug-Exception-File')));
        }

        return $this->client->getResponse()->getStatusCode();
    }

    private function body(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    // ------------------------------------------------------------------ matrice d'accès

    public function testTheAdministratorOfTheMunicipalityReadsItsArchivesInReadOnlyWithAMandatoryMotifAndAJournal(): void
    {
        $this->close();
        $dossier = $this->dossierOf($this->organizerId);
        $this->as($this->admin);

        self::assertSame(200, $this->get($this->archiveUrl($this->organizerId)));
        self::assertStringContainsString('Incendie du massif forestier', $this->body());
        self::assertSame(200, $this->get($this->archiveUrl($this->organizerId, '/evenements/'.$this->eventId())));
        $list = $this->body();
        self::assertStringContainsString('Consulter', $list);
        foreach (self::SENTINELS as $sentinel) {
            self::assertStringNotContainsStringIgnoringCase($sentinel, $list, 'la liste ne contient aucune donnée personnelle');
        }

        $consultation = $this->openDossier($this->admin, $this->organizerId, $dossier, 'LITIGE');

        self::assertNotNull($consultation);
        self::assertSame(200, $this->get($this->archiveUrl($this->organizerId, '/consultations/'.$consultation)));
        self::assertStringContainsStringIgnoringCase('Martin', $this->body(), 'après motif, le dossier est lisible');
        self::assertStringContainsString('lecture seule', $this->body());
        self::assertSame(1, $this->journalRows());
        $row = $this->em()->getConnection()->fetchAssociative('SELECT HEX(user_id) AS u, action, reason, HEX(request_municipality_id) AS d FROM archive_consultation');
        self::assertSame([strtoupper(str_replace('-', '', $this->admin->id()->toRfc4122())), 'DOSSIER_OUVERT', 'LITIGE', strtoupper(str_replace('-', '', $dossier))], array_values($row));
    }

    public function testANonDesignatedEventManagerInstructorOutsiderAndSuperAdminAreRefusedWithTheUniform404(): void
    {
        $this->close();
        $dossier = $this->dossierOf($this->organizerId);
        $foreign = $this->outsiderCommuneId;
        $cases = [
            'gestionnaire non désigné' => [$this->manager, $this->organizerId],
            'instructeur' => [$this->instructor, $this->organizerId],
            'commune étrangère (sa propre commune)' => [$this->outsider, $foreign],
            'commune étrangère (commune d\'autrui)' => [$this->outsider, $this->organizerId],
            'CWS sans appartenance' => [$this->superAdmin, $this->organizerId],
            'instructeur du partenaire sur la commune organisatrice' => [$this->partnerInstructor, $this->organizerId],
        ];
        foreach ($cases as $label => [$user, $commune]) {
            $this->as($user);
            foreach (['/evenements/'.$this->eventId(), '/dossiers/'.$dossier.'/ouvrir', '/journal'] as $suffix) {
                self::assertSame(404, $this->get($this->archiveUrl($commune, $suffix)), $label.' '.$suffix);
                $notFound[$label.$suffix] = $this->body();
            }
        }
        self::assertCount(1, array_unique($notFound), 'même réponse pour tous les refus : jamais « clos », « archive » ni « rôle »');
        self::assertSame(0, $this->journalRows());
    }

    public function testADesignatedEventManagerReadsOnlyTheEventAndMunicipalityItWasDesignatedFor(): void
    {
        $this->close();
        $dossier = $this->dossierOf($this->organizerId);
        $partnerDossier = $this->dossierOf($this->partnerId);
        $this->designate($this->manager, $this->organizerId, $this->admin);

        $this->as($this->manager);
        self::assertSame(200, $this->get($this->archiveUrl($this->organizerId, '/evenements/'.$this->eventId())));
        self::assertNotNull($this->openDossier($this->manager, $this->organizerId, $dossier));
        // La désignation ne vaut ni pour la commune partenaire (même utilisateur, autre commune), ni pour une autre cible.
        self::assertSame(404, $this->get($this->archiveUrl($this->partnerId, '/evenements/'.$this->eventId())));
        self::assertNull($this->openDossier($this->manager, $this->partnerId, $partnerDossier));
        self::assertSame(404, $this->get($this->archiveUrl($this->organizerId, '/journal')), 'le journal est réservé à l\'administrateur');
        // Un gestionnaire désigné ne gère ni désignation ni gel.
        self::assertSame(404, $this->postArchive($this->organizerId, '/evenements/'.$this->eventId().'/designations', ['user' => $this->manager->id()->toRfc4122()]));
    }

    public function testADossierOfAnotherMunicipalityIsNeverReadableThroughForgedIdentifiers(): void
    {
        $this->close();
        $partnerDossier = $this->dossierOf($this->partnerId);
        $organizerDossier = $this->dossierOf($this->organizerId);

        $this->as($this->admin);
        self::assertSame(404, $this->get($this->archiveUrl($this->organizerId, '/dossiers/'.$partnerDossier.'/ouvrir')), 'dossier de la commune partenaire dans l\'URL de l\'organisatrice');
        self::assertSame(404, $this->get($this->archiveUrl($this->partnerId, '/dossiers/'.$partnerDossier.'/ouvrir')), 'commune partenaire : l\'administrateur de l\'organisatrice n\'y est pas membre');
        self::assertSame(404, $this->get($this->archiveUrl($this->organizerId, '/dossiers/'.Uuid::v7()->toRfc4122().'/ouvrir')));
        self::assertSame(404, $this->get($this->archiveUrl($this->organizerId, '/evenements/'.Uuid::v7()->toRfc4122())));
        $consultation = $this->openDossier($this->admin, $this->organizerId, $organizerDossier);
        self::assertNotNull($consultation);
        // La consultation d'un autre administrateur de la MÊME commune n'est pas lisible.
        $this->as($this->organizerAdmin2);
        self::assertSame(404, $this->get($this->archiveUrl($this->organizerId, '/consultations/'.$consultation)));
        self::assertSame(404, $this->get($this->archiveUrl($this->partnerId, '/consultations/'.$consultation)));
    }

    public function testOnlyAClosedEventHasAnArchive(): void
    {
        $dossier = $this->dossierOf($this->organizerId);

        $this->as($this->admin);
        self::assertSame(404, $this->get($this->archiveUrl($this->organizerId, '/evenements/'.$this->eventId())), 'événement actif : aucune archive');
        self::assertSame(404, $this->get($this->archiveUrl($this->organizerId, '/dossiers/'.$dossier.'/ouvrir')));
        self::assertNull($this->openDossier($this->admin, $this->organizerId, $dossier));
        self::assertSame(0, $this->journalRows());
    }

    public function testAnExpiredArchiveWithoutHoldIsClosedAndAHoldKeepsItOpen(): void
    {
        $this->closeAndAge(13);
        $dossier = $this->dossierOf($this->organizerId);

        $this->as($this->admin);
        self::assertSame(404, $this->get($this->archiveUrl($this->organizerId, '/dossiers/'.$dossier.'/ouvrir')), 'archive échue : accès fermé en attendant la purge');
        self::assertStringNotContainsString('Ouvrir', $this->body());
        self::assertSame(200, $this->get($this->archiveUrl($this->organizerId, '/evenements/'.$this->eventId())), 'l\'événement reste listé, ses dossiers « échus »');
        self::assertStringContainsString('Échue', $this->body());

        static::getContainer()->get(\App\Archive\Application\LegalHoldService::class)->place($this->managed($this->admin), $this->organizerId, $dossier, 'LITIGE', null);
        $this->em()->clear();

        self::assertNotNull($this->openDossier($this->admin, $this->organizerId, $dossier), 'le gel maintient l\'accès à CE dossier');
    }

    // ------------------------------------------------------------------ motif, journal, lecture seule

    public function testTheMotifIsMandatoryAndAFailedValidationWritesNothing(): void
    {
        $this->close();
        $dossier = $this->dossierOf($this->organizerId);

        self::assertNull($this->openDossier($this->admin, $this->organizerId, $dossier, ''));
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->openDossier($this->admin, $this->organizerId, $dossier, 'AUTRE', ''));
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), '« autre » exige une précision');
        self::assertNull($this->openDossier($this->admin, $this->organizerId, $dossier, 'MOTIF_INVENTE'));
        self::assertSame(0, $this->journalRows());
        self::assertNotNull($this->openDossier($this->admin, $this->organizerId, $dossier, 'AUTRE', 'Demande de la préfecture'));
        self::assertSame('Demande de la préfecture', $this->em()->getConnection()->fetchOne('SELECT reason_comment FROM archive_consultation'));
    }

    public function testEveryAttachmentDownloadIsJournalledAndMustBelongToTheConsultedDossier(): void
    {
        $this->close();
        $dossier = $this->dossierOf($this->organizerId);
        $consultation = $this->openDossier($this->admin, $this->organizerId, $dossier) ?? self::fail('Consultation attendue.');

        self::assertSame(200, $this->get($this->archiveUrl($this->organizerId, '/consultations/'.$consultation.'/justificatifs/'.$this->attachmentId)));
        self::assertStringStartsWith('attachment;', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame(['DOSSIER_OUVERT' => 1, 'PIECE_TELECHARGEE' => 1], array_map('intval', $this->em()->getConnection()->fetchAllKeyValue('SELECT action, COUNT(*) FROM archive_consultation GROUP BY action')));
        self::assertSame(404, $this->get($this->archiveUrl($this->organizerId, '/consultations/'.$consultation.'/justificatifs/'.Uuid::v7()->toRfc4122())), 'pièce d\'une autre demande ou inconnue');
        self::assertSame(2, $this->journalRows(), 'un refus n\'écrit pas de ligne de téléchargement');
    }

    public function testTheOperationalScreensStayClosedAndTheArchiveOffersNoWriteRoute(): void
    {
        $this->close();
        $this->as($this->admin);

        self::assertSame(410, $this->get('/communes/'.$this->organizerId.'/demandes/'.$this->requestId));
        $router = static::getContainer()->get(RouterInterface::class);
        foreach ($router->getRouteCollection() as $name => $route) {
            if (str_starts_with((string) $name, 'app_archive_') && \in_array('POST', $route->getMethods(), true)) {
                // Les seules écritures sont des actes de gestion de l'administrateur ou le journal : jamais d'action sur le dossier.
                self::assertContains($name, ['app_archive_open', 'app_archive_designate', 'app_archive_designation_revoke', 'app_archive_hold'], $name);
            }
        }
    }

    public function testAnExpiredConsultationLinkSendsItsOwnerBackToTheMotifFormAndAWithdrawnDesignationCutsTheAccessAtOnce(): void
    {
        $this->close();
        $dossier = $this->dossierOf($this->organizerId);
        $this->designate($this->manager, $this->organizerId, $this->admin);
        $consultation = $this->openDossier($this->manager, $this->organizerId, $dossier) ?? self::fail('Consultation attendue.');
        $this->as($this->manager);
        self::assertSame(200, $this->get($this->archiveUrl($this->organizerId, '/consultations/'.$consultation)));

        $this->sql('UPDATE archive_consultation SET occurred_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 31 MINUTE)');
        $this->client->request('GET', $this->archiveUrl($this->organizerId, '/consultations/'.$consultation));
        self::assertTrue($this->client->getResponse()->isRedirect(), 'lien expiré : retour au formulaire de motif pour son propriétaire');
        self::assertStringContainsString('/ouvrir', (string) $this->client->getResponse()->headers->get('Location'));

        $this->sql('UPDATE archive_consultation SET occurred_at = UTC_TIMESTAMP()');
        $designation = Uuid::fromBinary((string) $this->em()->getConnection()->fetchOne('SELECT id FROM archive_manager_designation'))->toRfc4122();
        $this->as($this->admin);
        $crawler = $this->client->request('GET', $this->archiveUrl($this->organizerId, '/evenements/'.$this->eventId()));
        $token = (string) $crawler->filter('form[action$="/'.$designation.'/revoquer"] input[name=_token]')->attr('value');
        $this->client->request('POST', $this->archiveUrl($this->organizerId, '/designations/'.$designation.'/revoquer'), ['_token' => $token, 'event' => $this->eventId()]);
        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->as($this->manager);
        self::assertSame(404, $this->get($this->archiveUrl($this->organizerId, '/consultations/'.$consultation)), 'désignation révoquée : le lien déjà ouvert est coupé');
    }

    // ------------------------------------------------------------------ matrice des routes

    /** @param array<string, string> $fields */
    private function postArchive(string $municipalityId, string $suffix, array $fields = []): int
    {
        $this->client->request('POST', $this->archiveUrl($municipalityId, $suffix), $fields + ['_token' => 'x']);

        return $this->client->getResponse()->getStatusCode();
    }

    public function testEveryArchiveRouteRefusesEveryUnauthorisedUserWithTheUniform404(): void
    {
        $this->close();
        $router = static::getContainer()->get(RouterInterface::class);
        $routes = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            if (str_contains($route->getPath(), '/archives') || 'app_event_statistics_coordination' === $name || str_starts_with((string) $name, 'app_event_statistics_coordination_')) {
                $routes[$name] = $route;
            }
        }
        self::assertEqualsCanonicalizing(['app_archive_index', 'app_archive_event', 'app_archive_open', 'app_archive_consultation', 'app_archive_attachment', 'app_archive_designate', 'app_archive_designation_revoke', 'app_archive_hold', 'app_archive_journal', 'app_event_statistics_coordination', 'app_event_statistics_coordination_grant', 'app_event_statistics_coordination_revoke'], array_keys($routes), 'toute nouvelle route d\'archive doit être ajoutée à cette matrice et à la politique');

        $values = ['municipalityId' => $this->organizerId, 'eventId' => $this->eventId(), 'dossierId' => $this->dossierOf($this->organizerId), 'consultationId' => Uuid::v7()->toRfc4122(), 'attachmentId' => $this->attachmentId, 'designationId' => Uuid::v7()->toRfc4122(), 'grantId' => Uuid::v7()->toRfc4122(), 'action' => 'placer'];
        $users = ['gestionnaire non désigné' => $this->manager, 'instructeur' => $this->instructor, 'étranger' => $this->outsider, 'CWS' => $this->superAdmin, 'admin du partenaire' => $this->partnerAdmin];
        foreach ($routes as $name => $route) {
            $path = $route->getPath();
            foreach ($values as $key => $value) {
                $path = str_replace('{'.$key.'}', $value, $path);
            }
            foreach ($users as $label => $user) {
                $this->as($user);
                foreach ($route->getMethods() ?: ['GET'] as $method) {
                    $this->client->request($method, $path, 'GET' === $method ? [] : ['_token' => 'x', 'reason' => 'LITIGE', 'user' => $this->manager->id()->toRfc4122()]);
                    $member = \in_array($label, ['gestionnaire non désigné', 'instructeur'], true);
                    if ('app_archive_index' === $name && $member) {
                        // La page d'accueil des archives est une LISTE filtrée par habilitation : un membre sans habilitation y voit une liste vide, jamais un événement.
                        self::assertSame(200, $this->client->getResponse()->getStatusCode(), sprintf('%s %s', $label, $name));
                        self::assertStringNotContainsString('Incendie du massif forestier', (string) $this->client->getResponse()->getContent(), $label);
                        continue;
                    }
                    self::assertSame(404, $this->client->getResponse()->getStatusCode(), sprintf('%s %s %s', $label, $method, $name));
                }
            }
        }
        self::assertSame(0, $this->journalRows());
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM archive_manager_designation'));
    }
}
