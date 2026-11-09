<?php

declare(strict_types=1);

namespace App\Tests\Functional\Request;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RefusalReasonInitializer;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\TravelMode;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\PublicRequestTestCase;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Écran de correction d'identité (LOT 04.4, DM-111) dans le navigateur simulé : droits (INSTRUCTOR et MUNICIPALITY_ADMIN, jamais EVENT_MANAGER
 * seul ni une commune étrangère), CSRF, validations du dépôt avec saisie conservée, attestation, champs hors périmètre ignorés, conflit de
 * version, aucune donnée personnelle dans l'URL, aucun élargissement des droits d'instruction.
 */
#[Group('requires-request-schema')]
final class RequestIdentityFlowTest extends PublicRequestTestCase
{
    private User $instructor;
    private User $partnerInstructor;
    private User $admin;
    private User $manager;
    private User $foreign;
    private Municipality $foreignCommune;
    private string $organizerId;
    private string $partnerId;
    private string $requestId;
    private string $singleRequestId;

    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get(RefusalReasonInitializer::class)->initialize();
        $organizer = $this->em()->find(Municipality::class, $this->organizerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $partner = $this->em()->find(Municipality::class, $this->partnerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $this->organizerId = $organizer->id()->toRfc4122();
        $this->partnerId = $partner->id()->toRfc4122();
        $this->foreignCommune = MunicipalityBuilder::aMunicipality()->withInseeCode('13055')->build();
        [$this->instructor, $this->partnerInstructor, $this->admin, $this->manager, $this->foreign] = array_map(static fn (): User => UserBuilder::aUser()->build(), range(1, 5));
        $this->save(
            $this->foreignCommune,
            $this->instructor,
            $this->partnerInstructor,
            $this->admin,
            $this->manager,
            $this->foreign,
            MembershipBuilder::aMembership($this->instructor, $organizer)->withRoles(Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->partnerInstructor, $partner)->withRoles(Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->admin, $organizer)->withRoles(Role::MunicipalityAdmin)->build(),
            MembershipBuilder::aMembership($this->manager, $organizer)->withRoles(Role::EventManager)->build(),
            MembershipBuilder::aMembership($this->foreign, $this->foreignCommune)->withRoles(Role::Instructor)->build(),
        );
        $this->requestId = $this->deposit([$this->organizerZone, $this->partnerZone], 1);
        $this->singleRequestId = $this->deposit([$this->organizerZone], 2);
    }

    /**
     * @param list<\App\Event\Domain\EventZone> $zones
     */
    private function deposit(array $zones, int $key): string
    {
        $submitted = static::getContainer()->get(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
            $this->event->id()->toRfc4122(),
            'Martin',
            'Camille',
            null,
            'camille@exemple.fr',
            null,
            'private_person',
            'home',
            null,
            TravelMode::FootBike,
            [],
            array_map(static fn ($z): string => $z->id()->toRfc4122(), $zones),
            true,
        ), sprintf('identity-ui-%016d', $key));
        $this->em()->clear();

        return $submitted->requestId;
    }

    private function as(User $user): void
    {
        $this->client->loginUser($this->em()->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.'));
    }

    private function url(?string $commune = null, ?string $requestId = null): string
    {
        return '/communes/'.($commune ?? $this->organizerId).'/demandes/'.($requestId ?? $this->requestId).'/identite';
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function form(string $url, array $override = []): array
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $override + [
            '_token' => (string) $crawler->filter('input[name=_token]')->attr('value'),
            'version' => (string) $crawler->filter('input[name=version]')->attr('value'),
            'last' => 'Martin', 'first' => 'Camille', 'address' => '', 'email' => 'camille@exemple.fr', 'phone' => '', 'same' => '1',
        ];
    }

    /** @return array<string, mixed> */
    private function row(?string $requestId = null): array
    {
        $row = $this->em()->getConnection()->fetchAssociative('SELECT * FROM request_permit_request WHERE id = :id', ['id' => (string) hex2bin(str_replace('-', '', $requestId ?? $this->requestId))]);
        self::assertIsArray($row);

        return $row;
    }

    private function audits(): int
    {
        return $this->rows('audit_log WHERE action = \'REQUEST_IDENTITY_CORRECTED\'');
    }

    // ------------------------------------------------------------------ Affichage et droits

    public function testTheInstructorSeesTheCurrentValuesTheReferenceTheStatusAndTheMulticommunalWarning(): void
    {
        $this->as($this->instructor);
        $crawler = $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('Martin', $crawler->filter('#f-last')->attr('value'));
        self::assertSame('Camille', $crawler->filter('#f-first')->attr('value'));
        self::assertSame('camille@exemple.fr', $crawler->filter('#f-email')->attr('value'));
        $text = $crawler->filter('main')->text();
        self::assertStringContainsString('DEM-', $text);
        self::assertStringContainsString('En attente de décision', $text);
        self::assertStringContainsString('plusieurs communes', $text);
        self::assertStringContainsString('Une autre commune est concernée', $text);
        self::assertCount(1, $crawler->filter('input[name=same]:not(:checked)'), 'l\'attestation n\'est jamais pré-cochée');
        foreach (['last', 'first', 'address', 'email', 'phone'] as $field) {
            self::assertCount(1, $crawler->filter('input[name='.$field.']'), 'le champ « '.$field.' » doit être posté sous ce nom');
        }
    }

    public function testASingleCommuneRequestShowsNoMulticommunalWarning(): void
    {
        $this->as($this->instructor);
        $crawler = $this->client->request('GET', $this->url(null, $this->singleRequestId));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#identity-multi'));
    }

    public function testTheAdministratorHasATargetedAccessWithoutAnyInstructionRight(): void
    {
        $this->as($this->admin);
        $crawler = $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href$="/demandes/'.$this->requestId.'"]'), 'aucun lien vers la fiche d\'instruction');
        $this->client->request('GET', '/communes/'.$this->organizerId.'/demandes/'.$this->requestId);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/communes/'.$this->organizerId.'/demandes');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheInstructorFicheOffersTheCorrectionAction(): void
    {
        $this->as($this->instructor);
        $crawler = $this->client->request('GET', '/communes/'.$this->organizerId.'/demandes/'.$this->requestId);

        self::assertResponseIsSuccessful();
        self::assertSame($this->url(), $crawler->selectLink('Corriger les informations du bénéficiaire')->attr('href'));
    }

    public function testAnEventManagerAloneIsForbiddenOnBothMethods(): void
    {
        $this->as($this->manager);
        $this->client->request('GET', $this->url());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $this->url(), ['last' => 'Intrus', 'first' => 'X', 'email' => 'x@exemple.fr', 'same' => '1', 'version' => '0']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Martin', $this->row()['last_name']);
    }

    public function testAForeignCommuneAndForgedIdentifiersGetTheUniformNotFound(): void
    {
        $this->as($this->foreign);
        $this->client->request('GET', $this->url($this->foreignCommune->id()->toRfc4122()));
        self::assertResponseStatusCodeSame(404, 'commune étrangère, demande qui n\'est pas la sienne');
        $this->client->request('GET', $this->url($this->organizerId));
        self::assertResponseStatusCodeSame(404, 'commune dont il n\'est pas membre');

        $this->as($this->instructor);
        $this->client->request('GET', $this->url($this->partnerId));
        self::assertResponseStatusCodeSame(404, 'commune forgée dans l\'URL');
        $this->client->request('GET', $this->url($this->organizerId, Uuid::v7()->toRfc4122()));
        self::assertResponseStatusCodeSame(404, 'demande inexistante');

        $this->as($this->partnerInstructor);
        $this->client->request('GET', $this->url($this->partnerId, $this->singleRequestId));
        self::assertResponseStatusCodeSame(404, 'demande sans part dans sa commune');
        $this->client->request('POST', $this->url($this->partnerId, $this->singleRequestId), ['last' => 'Intrus', 'same' => '1', 'version' => '0']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Martin', $this->row($this->singleRequestId)['last_name']);
    }

    // ------------------------------------------------------------------ Correction

    public function testASuccessfulCorrectionRedirectsShowsTheNewValuesAndKeepsTheStatusAndTheReference(): void
    {
        $reference = $this->row()['public_reference'];
        $this->as($this->instructor);
        $form = $this->form($this->url(), ['last' => 'Dupont', 'phone' => '06 12 34 56 78', 'address' => '12 rue des Lilas']);

        $this->client->request('POST', $this->url(), $form);

        self::assertResponseRedirects($this->url());
        self::assertStringNotContainsStringIgnoringCase('dupont', (string) $this->client->getResponse()->headers->get('Location'), 'aucune donnée personnelle dans l\'URL');
        $crawler = $this->client->followRedirect();
        $text = $crawler->filter('main')->text();
        self::assertStringContainsString('Informations corrigées (nom, adresse, téléphone)', $text);
        self::assertStringContainsString('inchangés', $text);
        self::assertStringContainsString('1 autre commune concernée voit la correction', $text);
        self::assertSame('Dupont', $crawler->filter('#f-last')->attr('value'));
        self::assertSame('+33612345678', $this->row()['phone']);
        self::assertSame($reference, $this->row()['public_reference']);
        self::assertSame(1, $this->row()['identity_version']);
        self::assertSame(2, $this->audits(), 'une ligne d\'audit par commune concernée');
        self::assertSame(0, $this->rows('request_permit_request_decision'));
        self::assertSame('PENDING', $this->em()->getConnection()->fetchOne('SELECT MIN(status) FROM request_permit_request_municipality'));
    }

    public function testTheAdministratorCanCorrectToo(): void
    {
        $this->as($this->admin);
        $this->client->request('POST', $this->url(), $this->form($this->url(), ['first' => 'Camillia']));

        self::assertResponseRedirects($this->url());
        self::assertSame('Camillia', $this->row()['first_name']);
    }

    public function testASubmissionWithoutChangeShowsAnInformationAndWritesNothing(): void
    {
        $this->as($this->instructor);
        $this->client->request('POST', $this->url(), $this->form($this->url()));

        self::assertResponseRedirects($this->url());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Aucune modification', $crawler->filter('main')->text());
        self::assertSame(0, $this->row()['identity_version']);
        self::assertSame(0, $this->audits());
    }

    public function testTheBeneficiaryConfirmationIsRequiredAndTheInputIsKept(): void
    {
        $this->as($this->instructor);
        $crawler = $this->client->request('POST', $this->url(), $this->form($this->url(), ['last' => 'Autre', 'same' => '']));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('même personne', $crawler->filter('main')->text());
        self::assertSame('Autre', $crawler->filter('#f-last')->attr('value'), 'saisie conservée');
        self::assertSame('Martin', $this->row()['last_name']);
        self::assertSame(0, $this->audits());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'nom vide' => [['last' => ''], 'Indiquez votre nom'];
        yield 'e-mail invalide' => [['email' => 'pas-un-email'], 'ne semble pas valide'];
        yield 'téléphone invalide' => [['phone' => '12'], 'ne semble pas valide'];
        yield 'aucun contact' => [['email' => '', 'phone' => ''], 'au moins une adresse e-mail ou un numéro'];
        yield 'nom trop long' => [['last' => 'abcdefghij'.str_repeat('a', 130)], '120 caractères'];
    }

    /**
     * @param array<string, string> $override
     */
    #[DataProvider('invalid')]
    public function testValidationErrorsReuseTheDepositMessagesKeepTheInputAndWriteNothing(array $override, string $message): void
    {
        $this->as($this->instructor);
        $crawler = $this->client->request('POST', $this->url(), $this->form($this->url(), ['first' => 'Zoé'] + $override));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($message, $crawler->filter('main')->text());
        self::assertSame('Zoé', $crawler->filter('#f-first')->attr('value'), 'la saisie valide est conservée');
        self::assertSame('Camille', $this->row()['first_name']);
        self::assertSame(0, $this->audits());
    }

    public function testAnInvalidCsrfTokenIsRefusedWithoutWriting(): void
    {
        $this->as($this->instructor);
        $form = $this->form($this->url(), ['last' => 'Pirate', '_token' => 'forge']);
        $crawler = $this->client->request('POST', $this->url(), $form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('expiré', $crawler->filter('main')->text());
        self::assertSame('Pirate', $crawler->filter('#f-last')->attr('value'));
        self::assertSame('Martin', $this->row()['last_name']);
    }

    public function testFieldsOutsideTheScopeAreIgnoredAndTheRequestIsOtherwiseUntouched(): void
    {
        $before = $this->row();
        $parts = $this->em()->getConnection()->fetchAllAssociative('SELECT id, status FROM request_permit_request_municipality ORDER BY id');
        $this->as($this->instructor);
        $form = $this->form($this->url(), ['last' => 'Dupont'] + [
            'category' => 'professional', 'motif' => 'work', 'mode' => 'vehicle', 'plates' => ['AA-111-AA'], 'zones' => [$this->partnerZone->id()->toRfc4122()],
            'status' => 'APPROVED', 'reference' => 'DEM-PIRATE', 'decision' => 'APPROVED', 'municipality' => $this->partnerId, 'event' => Uuid::v7()->toRfc4122(), 'identity_version' => '99',
            'duration_mode' => 'H72', 'valid_until' => '2030-01-01',
        ]);

        $this->client->request('POST', $this->url(), $form);

        self::assertResponseRedirects($this->url());
        $after = $this->row();
        foreach (['public_reference', 'category_id', 'motif_id', 'travel_mode', 'event_id', 'origin'] as $column) {
            self::assertSame($before[$column], $after[$column], $column);
        }
        self::assertSame('Dupont', $after['last_name']);
        self::assertSame(1, $after['identity_version']);
        self::assertSame($parts, $this->em()->getConnection()->fetchAllAssociative('SELECT id, status FROM request_permit_request_municipality ORDER BY id'));
        self::assertSame(0, $this->rows('request_permit_request_decision'));
        self::assertSame(0, $this->rows('request_permit_request_vehicle'));
    }

    public function testAConcurrentCorrectionIsDetectedTheInputIsKeptAndTheStoredValuesAreShown(): void
    {
        $this->as($this->instructor);
        $form = $this->form($this->url(), ['last' => 'MonAgent']);
        // Entre l'ouverture et la validation, un autre agent corrige.
        $this->sql('UPDATE request_permit_request SET last_name = :n, identity_version = identity_version + 1 WHERE id = UNHEX(:id)', ['n' => 'AutreAgent', 'id' => str_replace('-', '', $this->requestId)]);

        $crawler = $this->client->request('POST', $this->url(), $form);

        self::assertResponseStatusCodeSame(409);
        $text = $crawler->filter('main')->text();
        self::assertStringContainsString('corrigées par ailleurs', $text);
        self::assertStringContainsString('Valeurs actuellement enregistrées', $text);
        self::assertStringContainsString('AutreAgent', $text);
        self::assertSame('MonAgent', $crawler->filter('#f-last')->attr('value'), 'ma saisie est conservée');
        self::assertSame('1', $crawler->filter('input[name=version]')->attr('value'), 'la version courante est reprise');
        self::assertCount(1, $crawler->filter('input[name=same]:not(:checked)'), 'l\'attestation est à confirmer de nouveau');
        self::assertSame('AutreAgent', $this->row()['last_name'], 'rien n\'a été écrasé');
        self::assertSame(0, $this->audits());
    }

    // ------------------------------------------------------------------ Point d'entrée « Corrections d'identité » (LOT 04.5)

    private function entry(?string $commune = null): string
    {
        return '/communes/'.($commune ?? $this->organizerId).'/corrections-identite';
    }

    private function referenceOf(string $requestId): string
    {
        return (string) $this->row($requestId)['public_reference'];
    }

    public function testTheAdministratorAndTheInstructorFindTheEntryPointInTheirNavigationAndTheEventManagerDoesNot(): void
    {
        foreach ([$this->admin, $this->instructor] as $agent) {
            $this->as($agent);
            $crawler = $this->client->request('GET', '/communes/'.$this->organizerId.'/evenements');
            self::assertResponseIsSuccessful();
            self::assertGreaterThan(0, $crawler->filter('a[href="'.$this->entry().'"]')->count(), 'lien de navigation « Corrections d’identité »');
        }
        $this->as($this->manager);
        $crawler = $this->client->request('GET', '/communes/'.$this->organizerId.'/evenements');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href="'.$this->entry().'"]'));
        $this->client->request('GET', $this->entry());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->entry().'?reference='.$this->referenceOf($this->requestId));
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheAdministratorFindsARequestByReferenceAndReachesTheCorrectionWithoutAnyInstructionRight(): void
    {
        $reference = $this->referenceOf($this->requestId);
        $this->as($this->admin);
        $crawler = $this->client->request('GET', $this->entry());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertCount(0, $crawler->filter('#identity-result-title'), 'aucune demande n\'est listée sans recherche');

        $crawler = $this->client->request('GET', $this->entry().'?reference='.strtolower($reference));
        self::assertResponseIsSuccessful();
        $text = $crawler->filter('main')->text();
        self::assertStringContainsString($reference, $text);
        self::assertStringContainsString('MARTIN', $text);
        self::assertStringContainsString('En attente', $text);
        $link = $crawler->selectLink('Corriger l’identité');
        self::assertSame($this->url(null, $this->requestId), $link->attr('href'));
        foreach ([$this->organizerZone->label(), $this->partnerZone->label(), 'Approuver', 'Refuser'] as $absent) {
            self::assertStringNotContainsStringIgnoringCase($absent, $text, 'aucune zone ni action d\'instruction');
        }
        self::assertCount(0, $crawler->filter('a[href$="/demandes/'.$this->requestId.'"]'));

        $this->client->click($link->link());
        self::assertResponseIsSuccessful();
    }

    public function testAnUnknownMalformedOrForeignReferenceGetsTheSameUniformAnswerAndExposesNothing(): void
    {
        // Demande sans part chez le partenaire : l'instructeur partenaire ne peut ni la retrouver ni en voir l'identité.
        $mine = $this->referenceOf($this->singleRequestId);
        $this->as($this->partnerInstructor);
        $answers = [];
        foreach ([$mine, 'DEM-2099-9999', "x' OR 1=1 --", '<script>', str_repeat('A', 60)] as $reference) {
            $crawler = $this->client->request('GET', $this->entry($this->partnerId).'?reference='.rawurlencode($reference));
            self::assertResponseIsSuccessful();
            $text = $crawler->filter('main')->text();
            self::assertStringContainsString('Aucune demande trouvée', $text);
            self::assertStringNotContainsStringIgnoringCase('Martin', $text);
            self::assertCount(0, $crawler->selectLink('Corriger l’identité'));
            $answers[] = preg_replace('/\s+/', ' ', $crawler->filter('#identity-search + section')->text());
        }
        self::assertCount(1, array_unique($answers), 'même réponse pour toute référence non retrouvable');

        $this->as($this->foreign);
        $this->client->request('GET', $this->entry($this->organizerId));
        self::assertResponseStatusCodeSame(404, 'commune dont l\'agent n\'est pas membre');
    }

    public function testTheAdministratorCorrectsADirectIssuanceMadeByAnotherAgentWithoutTouchingTheDecision(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->sql('UPDATE event_event SET starts_at = :s, ends_at = :e', ['s' => $now->modify('-1 day')->format('Y-m-d H:i:s'), 'e' => $now->modify('+10 days')->format('Y-m-d H:i:s')]);
        $instructor = $this->em()->find(User::class, $this->instructor->id()) ?? self::fail('Utilisateur absent.');
        $issued = static::getContainer()->get(RequestSubmissionService::class)->issueDirectly(
            $instructor,
            $this->organizerId,
            new SubmitRequestCommand($this->event->id()->toRfc4122(), 'Martin', 'Camille', null, 'camille@exemple.fr', null, 'private_person', 'home', null, TravelMode::FootBike, [], [$this->organizerZone->id()->toRfc4122()], true),
            \App\Request\Domain\ApprovalDuration::untilClosure(),
            'identity-entry-issuance-0001',
        );
        $this->em()->clear();
        $decision = $this->em()->getConnection()->fetchAssociative('SELECT outcome, path, valid_from, valid_until, decided_at FROM request_permit_request_decision');

        $this->as($this->admin);
        $this->client->request('GET', '/communes/'.$this->organizerId.'/delivrances/'.$issued->requestId);
        self::assertResponseStatusCodeSame(404, 'la confirmation reste réservée à l\'agent émetteur : le point d\'entrée ne la contourne pas');

        $crawler = $this->client->request('GET', $this->entry().'?reference='.$issued->reference);
        self::assertStringContainsString('Approuvée', $crawler->filter('main')->text());
        $this->client->click($crawler->selectLink('Corriger l’identité')->link());
        $this->client->request('POST', $this->url(null, $issued->requestId), $this->form($this->url(null, $issued->requestId), ['last' => 'Corrigé']));

        self::assertResponseRedirects($this->url(null, $issued->requestId));
        self::assertSame('Corrigé', $this->row($issued->requestId)['last_name']);
        self::assertSame($decision, $this->em()->getConnection()->fetchAssociative('SELECT outcome, path, valid_from, valid_until, decided_at FROM request_permit_request_decision'));
        self::assertSame($issued->reference, $this->row($issued->requestId)['public_reference']);
    }

    public function testTheAdministratorCannotConsultTheFicheNorInstructNorListEvenAfterUsingTheEntryPoint(): void
    {
        $this->as($this->admin);
        $this->client->request('GET', $this->entry().'?reference='.$this->referenceOf($this->requestId));
        $base = '/communes/'.$this->organizerId.'/demandes';
        foreach (['GET' => [$base, $base.'/'.$this->requestId, $base.'/'.$this->requestId.'/approuver', $base.'/'.$this->requestId.'/refuser'], 'POST' => [$base.'/'.$this->requestId.'/approuver', $base.'/'.$this->requestId.'/refuser']] as $method => $urls) {
            foreach ($urls as $url) {
                $this->client->request($method, $url, 'POST' === $method ? ['step' => 'confirm', 'mode' => 'H24', 'reason' => 'duplicate'] : []);
                self::assertResponseStatusCodeSame(403, $method.' '.$url);
            }
        }
        self::assertSame(0, $this->rows('request_permit_request_decision'));
    }
}
