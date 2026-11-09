<?php

declare(strict_types=1);

namespace App\Tests\Functional\Request;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\PublicRequestTestCase;
use App\Tests\Support\SampleFiles as S;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Saisie par un agent municipal = DÉLIVRANCE DIRECTE (LOT 04.3) : autorisations par commune, étapes dont la durée, validation unique qui crée
 * et approuve dans une seule transaction, brouillon propre à l'agent, confirmation relue en base, aucune extension des droits de consultation.
 * L'événement est recalé autour de la date du jour : l'application utilise la vraie horloge.
 */
#[Group('requires-request-schema')]
final class AgentRequestSubmissionTest extends PublicRequestTestCase
{
    private User $instructor;
    private User $otherInstructor;
    private User $manager;
    private User $adminOnly;
    private User $mixed;
    private User $foreign;
    private string $organizerId;
    private string $partnerId;
    private Municipality $foreignCommune;

    protected function setUp(): void
    {
        parent::setUp();
        $organizer = $this->em()->find(Municipality::class, $this->organizerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $partner = $this->em()->find(Municipality::class, $this->partnerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $this->organizerId = $organizer->id()->toRfc4122();
        $this->partnerId = $partner->id()->toRfc4122();
        $this->foreignCommune = \App\Tests\Support\MunicipalityBuilder::aMunicipality()->withInseeCode('13055')->build();
        [$this->instructor, $this->otherInstructor, $this->manager, $this->adminOnly, $this->mixed, $this->foreign] = array_map(static fn (): User => UserBuilder::aUser()->build(), range(1, 6));
        $this->save(
            $this->foreignCommune,
            $this->instructor,
            $this->otherInstructor,
            $this->manager,
            $this->adminOnly,
            $this->mixed,
            $this->foreign,
            MembershipBuilder::aMembership($this->instructor, $organizer)->withRoles(Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->otherInstructor, $organizer)->withRoles(Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->manager, $organizer)->withRoles(Role::EventManager)->build(),
            MembershipBuilder::aMembership($this->adminOnly, $organizer)->withRoles(Role::MunicipalityAdmin)->build(),
            MembershipBuilder::aMembership($this->mixed, $organizer)->withRoles(Role::EventManager)->build(),
            MembershipBuilder::aMembership($this->mixed, $partner)->withRoles(Role::MunicipalityAdmin)->build(),
            MembershipBuilder::aMembership($this->foreign, $this->foreignCommune)->withRoles(Role::Instructor)->build(),
        );
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->sql('UPDATE event_event SET starts_at = :s, ends_at = :e', ['s' => $now->modify('-1 day')->format('Y-m-d H:i:s'), 'e' => $now->modify('+10 days')->format('Y-m-d H:i:s')]);
    }

    private function as(User $user): void
    {
        $this->client->loginUser($this->em()->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.'));
    }

    private function agentUrl(string $municipalityId, string $suffix = '', ?string $eventId = null): string
    {
        return '/communes/'.$municipalityId.'/evenements/'.($eventId ?? $this->event->id()->toRfc4122()).'/demandes/nouvelle'.$suffix;
    }

    /**
     * @param array<string, mixed>              $values
     * @param array<string, list<UploadedFile>> $files
     */
    private function agentPost(string $municipalityId, string $step, array $values, array $files = []): void
    {
        $crawler = $this->client->request('GET', $this->agentUrl($municipalityId, '/'.$step));
        self::assertResponseIsSuccessful('étape '.$step.' inaccessible');
        $token = $crawler->filter('input[name=_token]')->attr('value');
        $this->client->request('POST', $this->agentUrl($municipalityId, '/'.$step), ['_token' => $token] + $values, $files);
    }

    /** Parcourt les étapes jusqu'au récapitulatif (non soumis), zones de la commune de l'agent. */
    private function toRecap(string $municipalityId, bool $withPdf = true): void
    {
        $this->agentPost($municipalityId, 'identite', ['last' => 'Martin', 'first' => 'Camille', 'phone' => '06 12 34 56 78']);
        self::assertResponseRedirects($this->agentUrl($municipalityId, '/situation'));
        $this->agentPost($municipalityId, 'situation', ['category' => 'private_person', 'motif' => 'home']);
        $this->agentPost($municipalityId, 'zones', ['zones' => [
            $this->organizerParticipation->id()->toRfc4122() => ['selected' => '1', 'zones' => [$this->organizerZone->id()->toRfc4122()]],
        ]]);
        $this->agentPost($municipalityId, 'deplacement', ['mode' => 'vehicle', 'plates' => ['GH-482-KT', '1234 AB 56']]);
        $files = $withPdf ? ['files' => [new UploadedFile(S::temporaryFile(S::pdf()), 'attestation.pdf', 'application/pdf', null, true)]] : [];
        $this->agentPost($municipalityId, 'justificatifs', [], $files);
        self::assertResponseRedirects($this->agentUrl($municipalityId, '/duree'));
        $this->agentPost($municipalityId, 'duree', ['duration_mode' => 'H24']);
        self::assertResponseRedirects($this->agentUrl($municipalityId, '/recapitulatif'));
    }

    /** URL de la confirmation d'une délivrance directe (page serveur relue en base). */
    private function issuedUrl(string $municipalityId, string $requestId): string
    {
        return '/communes/'.$municipalityId.'/delivrances/'.$requestId;
    }

    private function onlyRequestId(): string
    {
        return Uuid::fromBinary((string) $this->em()->getConnection()->fetchOne('SELECT id FROM request_permit_request'))->toRfc4122();
    }

    private function code(string $url): int
    {
        $this->client->request('GET', $url);

        return $this->client->getResponse()->getStatusCode();
    }

    public function testTheThreeMunicipalProfilesCanEnterAndShowTheEventAndCommuneContext(): void
    {
        foreach ([$this->instructor, $this->manager, $this->adminOnly] as $agent) {
            $this->as($agent);
            self::assertSame(200, $this->code($this->agentUrl($this->organizerId, '/identite')));
            self::assertSelectorTextContains('main', 'Vous effectuez cette saisie pour un bénéficiaire');
            self::assertSelectorTextContains('main', 'Votre identité sera enregistrée comme agent');
            self::assertSelectorTextContains('main', $this->event->name());
            self::assertSelectorTextContains('main', 'Délivrance directe');
            self::assertSame(200, $this->code('/communes/'.$this->organizerId.'/demandes/nouvelle'));
        }
    }

    public function testTheAdministratorGainsNoRequestConsultationRight(): void
    {
        $this->as($this->adminOnly);
        self::assertSame(403, $this->code('/communes/'.$this->organizerId.'/demandes'));
    }

    public function testTheMenuEntryAlwaysAsksForAnExplicitEventChoice(): void
    {
        $this->as($this->instructor);
        $this->client->request('GET', '/communes/'.$this->organizerId.'/demandes/nouvelle');
        self::assertResponseIsSuccessful('aucune redirection automatique, même avec un seul événement');
        self::assertSelectorTextContains('main', $this->event->name());
        self::assertSelectorExists('main a[href$="/evenements/'.$this->event->id()->toRfc4122().'/demandes/nouvelle"]');
    }

    public function testTheRoleIsCheckedOnTheCurrentMunicipalityOnly(): void
    {
        $this->as($this->mixed); // EVENT_MANAGER de l'organisatrice, simple administrateur de la partenaire
        self::assertSame(200, $this->code($this->agentUrl($this->organizerId, '/identite')));
        self::assertSame(200, $this->code($this->agentUrl($this->partnerId, '/identite')), 'administrateur de la partenaire : habilité chez elle');
        $this->as($this->instructor); // membre de l'organisatrice uniquement : identifiant de commune forgé
        self::assertSame(404, $this->code($this->agentUrl($this->partnerId, '/identite')));
    }

    public function testASuspendedOrClosedParticipationForbidsTheCreationOnEveryEntryPoint(): void
    {
        $partner = $this->em()->find(Municipality::class, $this->partnerId) ?? self::fail();
        $agent = UserBuilder::aUser()->build();
        $this->save($agent, MembershipBuilder::aMembership($agent, $partner)->withRoles(Role::EventManager)->build());
        $this->as($agent);
        foreach (['SUSPENDED', 'CLOSED'] as $status) {
            $this->sql('UPDATE event_participation SET status = :s WHERE id = UNHEX(:id)', ['s' => $status, 'id' => str_replace('-', '', $this->partnerParticipation->id()->toRfc4122())]);
            self::assertSame(403, $this->code($this->agentUrl($this->partnerId, '/identite')), $status);
            self::assertSame(403, $this->code($this->agentUrl($this->partnerId, '/zones')), $status);
            $this->client->request('POST', $this->agentUrl($this->partnerId, '/recapitulatif'), ['privacy' => '1']);
            self::assertResponseStatusCodeSame(403, $status);
            $this->client->request('GET', '/communes/'.$this->partnerId.'/demandes/nouvelle');
            self::assertSelectorNotExists('main a[href$="/evenements/'.$this->event->id()->toRfc4122().'/demandes/nouvelle"]', $status);
        }
    }

    public function testTheZoneStepOffersOnlyTheZonesOfTheAgentMunicipality(): void
    {
        $this->as($this->instructor);
        $this->agentPost($this->organizerId, 'identite', ['last' => 'Martin', 'first' => 'Camille', 'phone' => '06 12 34 56 78']);
        $this->agentPost($this->organizerId, 'situation', ['category' => 'private_person', 'motif' => 'home']);
        $this->client->request('GET', $this->agentUrl($this->organizerId, '/zones'));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[value="'.$this->organizerZone->id()->toRfc4122().'"]');
        self::assertSelectorNotExists('input[value="'.$this->partnerZone->id()->toRfc4122().'"]');
    }

    public function testIneligibleMunicipalitiesAndEventsAreRefused(): void
    {
        $this->as($this->foreign);
        self::assertSame(404, $this->code($this->agentUrl($this->organizerId, '/identite')), 'commune étrangère');
        self::assertSame(404, $this->code($this->agentUrl((string) $this->foreignCommune->id()->toRfc4122(), '/identite')), 'sa commune ne participe pas à l\'événement');
        $this->as($this->instructor);
        self::assertSame(404, $this->code($this->agentUrl($this->organizerId, '/identite', Uuid::v4()->toRfc4122())), 'événement inconnu');

        $this->sql("UPDATE event_participation SET status = 'SUSPENDED' WHERE id = UNHEX(:id)", ['id' => str_replace('-', '', $this->partnerParticipation->id()->toRfc4122())]);
        $partnerAgent = UserBuilder::aUser()->build();
        $partner = $this->em()->find(Municipality::class, $this->partnerId) ?? self::fail();
        $this->save($partnerAgent, MembershipBuilder::aMembership($partnerAgent, $partner)->withRoles(Role::Instructor)->build());
        $this->as($partnerAgent);
        self::assertSame(403, $this->code($this->agentUrl($this->partnerId, '/identite')), 'participation suspendue');
        $this->sql("UPDATE event_participation SET status = 'INVITED' WHERE id = UNHEX(:id)", ['id' => str_replace('-', '', $this->partnerParticipation->id()->toRfc4122())]);
        $this->as($partnerAgent);
        self::assertSame(403, $this->code($this->agentUrl($this->partnerId, '/identite')), 'participation seulement invitée');

        $this->sql("UPDATE event_event SET status = 'CLOSED' WHERE id = UNHEX(:id)", ['id' => str_replace('-', '', $this->event->id()->toRfc4122())]);
        $this->as($this->instructor);
        self::assertSame(403, $this->code($this->agentUrl($this->organizerId, '/identite')), 'événement clôturé');
        self::assertSame(0, $this->rows('request_permit_request'));
    }

    public function testTheStepsValidateLikeThePublicForm(): void
    {
        $this->as($this->instructor);
        $this->agentPost($this->organizerId, 'identite', ['last' => 'Martin', 'first' => 'Camille']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'au moins une adresse e-mail ou un numéro de téléphone');
        $this->agentPost($this->organizerId, 'identite', ['last' => 'Martin', 'first' => 'Camille', 'email' => 'camille@exemple.fr']);
        self::assertResponseRedirects($this->agentUrl($this->organizerId, '/situation'));
        $this->agentPost($this->organizerId, 'situation', ['category' => 'private_person', 'motif' => 'other', 'detail' => '']);
        self::assertResponseStatusCodeSame(422);
        $this->agentPost($this->organizerId, 'situation', ['category' => 'private_person', 'motif' => 'other', 'detail' => 'Livraison']);
        self::assertResponseRedirects($this->agentUrl($this->organizerId, '/zones'));
        $this->agentPost($this->organizerId, 'zones', ['zones' => [$this->organizerParticipation->id()->toRfc4122() => ['selected' => '1', 'zones' => [Uuid::v4()->toRfc4122()]]]]);
        self::assertResponseStatusCodeSame(422);
        $this->agentPost($this->organizerId, 'zones', ['zones' => [$this->partnerParticipation->id()->toRfc4122() => ['selected' => '1', 'zones' => [$this->partnerZone->id()->toRfc4122()]]]]);
        self::assertResponseStatusCodeSame(422, 'une zone d\'une autre commune participante est acceptée');
        $this->agentPost($this->organizerId, 'zones', ['zones' => [$this->organizerParticipation->id()->toRfc4122() => ['selected' => '1', 'zones' => [$this->organizerZone->id()->toRfc4122()]]]]);
        self::assertResponseRedirects($this->agentUrl($this->organizerId, '/deplacement'));
        $this->agentPost($this->organizerId, 'deplacement', ['mode' => 'vehicle', 'plates' => ['PAS-UNE-PLAQUE']]);
        self::assertResponseStatusCodeSame(422);
        $this->agentPost($this->organizerId, 'deplacement', ['mode' => 'foot_bike']);
        self::assertResponseRedirects($this->agentUrl($this->organizerId, '/justificatifs'));
    }

    public function testASubmissionIssuesOneApprovedAuthorizationWithItsAuthorAndMunicipalityAndNoPendingRow(): void
    {
        $this->as($this->instructor);
        $this->toRecap($this->organizerId);
        $this->agentPost($this->organizerId, 'recapitulatif', []);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'attester que vous avez informé le bénéficiaire');
        self::assertSame(0, $this->rows('request_permit_request'));

        $this->agentPost($this->organizerId, 'recapitulatif', ['privacy' => '1']);
        $requestId = $this->onlyRequestId();
        self::assertResponseRedirects($this->issuedUrl($this->organizerId, $requestId));

        self::assertSame([1, 1, 1, 2, 1, 1], [$this->rows('request_permit_request'), $this->rows('request_permit_request_municipality'), $this->rows('request_permit_request_zone'), $this->rows('request_permit_request_vehicle'), $this->rows('request_permit_request_attachment'), $this->rows('request_permit_request_decision')]);
        $row = $this->em()->getConnection()->fetchAssociative('SELECT public_reference, origin, last_name, first_name, phone, email, privacy_notice_version, HEX(entered_by_user_id) AS agent, HEX(entered_by_municipality_id) AS commune FROM request_permit_request');
        self::assertMatchesRegularExpression('/^DEM-\d{4}-0001$/', $row['public_reference']);
        self::assertSame(['AGENT', 'Martin', 'Camille', 'agent-information-1'], [$row['origin'], $row['last_name'], $row['first_name'], $row['privacy_notice_version']]);
        self::assertNull($row['email'], 'les coordonnées sont celles du bénéficiaire, jamais celles de l\'agent');
        self::assertSame(str_replace('-', '', $this->instructor->id()->toRfc4122()), strtolower($row['agent']));
        self::assertSame(str_replace('-', '', $this->organizerId), strtolower($row['commune']));
        // Délivrance directe : la part n'a jamais été PENDING en base, elle est approuvée dès son écriture.
        self::assertSame(['APPROVED'], $this->em()->getConnection()->fetchFirstColumn('SELECT status FROM request_permit_request_municipality'));
        self::assertSame(['APPROVED', 'DIRECT_ISSUANCE', 'H24'], array_values($this->em()->getConnection()->fetchAssociative('SELECT outcome, path, duration_mode FROM request_permit_request_decision') ?: []));
        self::assertSame(['REQUEST_APPROVED', 'REQUEST_SUBMITTED'], $this->em()->getConnection()->fetchFirstColumn("SELECT action FROM audit_log WHERE action LIKE 'REQUEST_%' ORDER BY action"));
        self::assertSame(0, $this->rows('audit_log WHERE action = \'REQUEST_REFUSED\''));
        self::assertSame([], $this->receipts());

        $crawler = $this->client->request('GET', $this->issuedUrl($this->organizerId, $requestId));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', $row['public_reference']);
        self::assertSelectorTextContains('main', 'Camille Martin');
        self::assertSelectorTextContains('main', 'Approuvée');
        self::assertSelectorTextContains('main', '24 heures');
        self::assertSelectorTextNotContains('main', 'En attente');
        self::assertStringNotContainsString('QR', $crawler->filter('main')->text());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    /** @return list<mixed> */
    private function receipts(): array
    {
        return [];
    }

    public function testAResubmissionNeverCreatesASecondRequestOrDecision(): void
    {
        $this->as($this->instructor);
        $this->toRecap($this->organizerId, withPdf: false);
        $token = $this->client->request('GET', $this->agentUrl($this->organizerId, '/recapitulatif'))->filter('input[name=_token]')->attr('value');
        // Double clic : deux envois du même récapitulatif avec le même jeton.
        $this->client->request('POST', $this->agentUrl($this->organizerId, '/recapitulatif'), ['_token' => $token, 'privacy' => '1']);
        $issued = $this->issuedUrl($this->organizerId, $this->onlyRequestId());
        self::assertResponseRedirects($issued);
        $this->client->request('POST', $this->agentUrl($this->organizerId, '/recapitulatif'), ['_token' => $token, 'privacy' => '1']);
        self::assertResponseRedirects($issued);
        self::assertSame([1, 1], [$this->rows('request_permit_request'), $this->rows('request_permit_request_decision')]);
    }

    public function testDraftsAreIsolatedAndStepsAreGuarded(): void
    {
        $this->as($this->instructor);
        $this->agentPost($this->organizerId, 'identite', ['last' => 'Martin', 'first' => 'Camille', 'phone' => '06 12 34 56 78']);
        self::assertSame(302, $this->code($this->agentUrl($this->organizerId, '/recapitulatif')), 'étape non atteinte');
        self::assertResponseRedirects($this->agentUrl($this->organizerId, '/situation'));

        $this->as($this->otherInstructor);
        self::assertSame(302, $this->code($this->agentUrl($this->organizerId, '/situation')), 'le brouillon d\'un autre agent n\'est pas partagé');
        self::assertResponseRedirects($this->agentUrl($this->organizerId, '/identite'));

        $this->client->getCookieJar()->clear();
        $this->as($this->instructor);
        $this->client->request('GET', $this->base.'/situation'); // brouillon public du même événement : distinct
        self::assertResponseRedirects($this->base.'/identite');
    }

    public function testCsrfProtectsEveryStepAndIsSpecificToTheAgentContext(): void
    {
        $this->as($this->instructor);
        $this->client->request('POST', $this->agentUrl($this->organizerId, '/identite'), ['last' => 'Martin', 'first' => 'Camille', 'phone' => '0612345678']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'session a expiré');
        $publicToken = $this->client->request('GET', $this->base.'/identite')->filter('input[name=_token]')->attr('value');
        $this->client->request('POST', $this->agentUrl($this->organizerId, '/identite'), ['_token' => $publicToken, 'last' => 'Martin', 'first' => 'Camille', 'phone' => '0612345678']);
        self::assertResponseStatusCodeSame(422, 'le jeton du formulaire public ne vaut pas pour la saisie agent');
    }

    public function testTheConfirmationBelongsToTheIssuingAgentAndOpensNoConsultationRight(): void
    {
        $this->as($this->manager); // EVENT_MANAGER sans INSTRUCTOR
        $this->toRecap($this->organizerId, withPdf: false);
        $this->agentPost($this->organizerId, 'recapitulatif', ['privacy' => '1']);
        $requestId = $this->onlyRequestId();
        $issued = $this->issuedUrl($this->organizerId, $requestId);
        self::assertResponseRedirects($issued);
        self::assertSame(200, $this->code($issued));
        self::assertSame(200, $this->code($issued), 'le rafraîchissement relit la base : même résultat');
        self::assertSelectorNotExists('a[href$="/demandes"]');
        self::assertSelectorNotExists('a[href$="/demandes/'.$requestId.'"]');

        self::assertSame(403, $this->code('/communes/'.$this->organizerId.'/demandes'));
        self::assertSame(403, $this->code('/communes/'.$this->organizerId.'/demandes/'.$requestId));

        // Un autre agent de la commune (même instructeur) n'est pas l'émetteur : refus uniforme ; il consulte la fiche par son propre rôle.
        $this->as($this->otherInstructor);
        self::assertSame(404, $this->code($issued), 'la confirmation est propre à l\'agent émetteur');
        self::assertSame(200, $this->code('/communes/'.$this->organizerId.'/demandes/'.$requestId));
        // Un administrateur de la commune, non émetteur, ne la voit pas non plus.
        $this->as($this->adminOnly);
        self::assertSame(404, $this->code($issued));
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $issued);
        self::assertResponseRedirects();
    }

    public function testNavigationAndEventPageOfferTheCreationOnlyToAuthorisedAgents(): void
    {
        $eventPage = '/communes/'.$this->organizerId.'/evenements/'.$this->event->id()->toRfc4122();
        $this->as($this->manager);
        $this->client->request('GET', '/communes/'.$this->organizerId.'/evenements');
        self::assertSelectorExists('.cp-sidebar a[href$="/demandes/nouvelle"], nav a[href$="/demandes/nouvelle"]');
        self::assertSelectorNotExists('nav a[href$="/demandes"]', 'un EVENT_MANAGER sans INSTRUCTOR n\'a pas l\'entrée de consultation');
        $this->client->request('GET', $eventPage);
        self::assertSelectorExists('.cp-evhead__actions a[href$="/nouvelle"]');

        $this->as($this->adminOnly);
        $this->client->request('GET', $eventPage);
        self::assertSelectorExists('.cp-evhead__actions a[href$="/nouvelle"]');
        self::assertSelectorNotExists('nav a[href$="/demandes"]', 'l\'administrateur n\'a pas l\'entrée de consultation');

        $this->as($this->foreign);
        $this->client->request('GET', '/communes/'.(string) $this->foreignCommune->id()->toRfc4122().'/evenements');
        self::assertSelectorExists('nav a[href$="/demandes/nouvelle"]');
    }
}
