<?php

declare(strict_types=1);

namespace App\Tests\Functional\Onboarding;

use App\Event\Application\Service\EventLifecycleService;
use App\Identity\Domain\User;
use App\Municipality\Application\MembershipManager;
use App\Municipality\Application\EventCreationPolicy;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\NationalCommune;
use App\Municipality\Domain\NationalCommuneRecord;
use App\Onboarding\Application\InvitationDispatcher;
use App\Onboarding\Application\InvitationNotifier;
use App\Tests\Support\EventFixtures;
use App\Tests\Support\MunicipalPagesTestCase;
use App\Tests\Support\RecordingInvitationNotifier;
use App\Tests\Support\UserBuilder;

/**
 * Liens d'invitation (MySQL de test) : page d'accueil publique qui ne montre que le contenu de l'e-mail, liens invalides,
 * expirés, utilisés ou remplacés, délégation de l'administrateur communal, inscription par lien personnel (création de compte
 * ou connexion), attestation, rôle administrateur seul, aucune commune déjà administrée, aucun abonnement, usurpation.
 *
 * Les jetons viennent d'un notifier d'enregistrement (aucun e-mail) ; le contenu réel des e-mails est testé ailleurs.
 */
final class InvitationLinkPageTest extends MunicipalPagesTestCase
{
    private RecordingInvitationNotifier $mails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
        $this->mails = new RecordingInvitationNotifier();
        static::getContainer()->set(InvitationNotifier::class, $this->mails);
    }

    private function referential(string $code = '33503', string $name = 'Saumos'): void
    {
        $this->em()->persist(new NationalCommune(new NationalCommuneRecord($code, $name, substr($code, 0, 2), '75', ['33680'], 1800), 'test', EventFixtures::now()));
        $this->em()->flush();
    }

    /** L'organisatrice invite la commune (service) ; renvoie le jeton du lien de contact. */
    private function invited(string $insee = '33503', string $email = 'contact@mairie.example', ?string $eventId = null, string $message = 'Rejoignez la coordination.'): string
    {
        static::getContainer()->get(InvitationDispatcher::class)->invite($this->user($this->w->adminO), $eventId ?? $this->fire, $insee, $email, $message);

        return $this->mails->last('CONTACT');
    }

    private function visitor(): void
    {
        $this->client->getCookieJar()->clear();
    }

    private function open(string $token): string
    {
        $this->client->request('GET', '/invitation/'.$token);
        if (303 === $this->httpStatus()) {
            $this->client->request('GET', $this->location());
        }

        return (string) $this->client->getResponse()->getContent();
    }

    private function csrf(string $html, string $action): string
    {
        self::assertSame(1, preg_match('#action="[^"]*'.preg_quote($action, '#').'"[^>]*>.*?name="_token" value="([^"]+)"#s', $html, $m), 'formulaire '.$action.' absent');

        return $m[1];
    }

    private function delegate(string $contactToken, string $adminEmail = 'directrice@mairie.example'): string
    {
        $html = $this->open($contactToken);
        $this->client->request('POST', '/invitation/delegation', ['administrator_email' => $adminEmail, '_token' => $this->csrf($html, '/invitation/delegation')]);

        return (string) $this->client->getResponse()->getContent();
    }

    private function links(string $where = '1 = 1'): int
    {
        return $this->scalar('SELECT COUNT(*) FROM onboarding_invitation_link WHERE '.$where);
    }

    private function communeId(string $insee): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT BIN_TO_UUID(id) FROM municipality WHERE insee_code = ?', [$insee]);
    }

    private function memberships(string $insee): int
    {
        return $this->scalar('SELECT COUNT(*) FROM municipality_membership mm JOIN municipality m ON m.id = mm.municipality_id WHERE m.insee_code = ?', [$insee]);
    }

    private function audits(string $action): int
    {
        return $this->scalar('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action]);
    }

    /** @return array{string, string} jeton du lien personnel, adresse */
    private function adminLink(string $insee = '33503', string $adminEmail = 'directrice@mairie.example'): array
    {
        $this->delegate($this->invited($insee), $adminEmail);

        return [$this->mails->last('ADMINISTRATOR'), $adminEmail];
    }

    /**
     * @param array<string, string> $fields
     */
    private function join(string $html, array $fields): string
    {
        $this->client->request('POST', '/invitation/rejoindre', $fields + ['_token' => $this->csrf($html, '/invitation/rejoindre')]);

        return (string) $this->client->getResponse()->getContent();
    }

    /** @return array<string, string> */
    private function signup(string $password = 'une phrase de passe longue', string $attest = '1'): array
    {
        return ['first_name' => 'Dominique', 'last_name' => 'Martin', 'password' => $password, 'password_confirm' => $password, 'attest' => $attest];
    }

    // ═══ Page d'accueil du lien de contact ══════════════════════════════════

    public function testOpeningAValidLinkTakesTheTokenOutOfTheUrlAndShowsOnlyTheEmailContent(): void
    {
        $this->referential();
        $token = $this->invited(message: 'Rejoignez la coordination du périmètre ouest.');
        $this->visitor();

        $this->client->request('GET', '/invitation/'.$token);
        self::assertSame(303, $this->httpStatus());
        self::assertSame('/invitation', parse_url($this->location(), \PHP_URL_PATH), 'le jeton quitte l’URL');
        self::assertStringNotContainsString($token, $this->location());
        $headers = $this->client->getResponse()->headers;
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertSame('no-referrer', $headers->get('Referrer-Policy'));

        $this->client->request('GET', '/invitation');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString($this->w->org->name(), $html);
        self::assertStringContainsString('Incendie du massif forestier', $html);
        self::assertStringContainsString('Saumos', $html);
        self::assertStringContainsString('Rejoignez la coordination du périmètre ouest.', $html);
        self::assertStringNotContainsString($token, $html);
        self::assertStringContainsString('<meta name="referrer" content="no-referrer">', $html);
        self::assertStringContainsString('noindex', $html);
        // Rien de sensible avant autorisation : ni zones, ni autres communes, ni consignes.
        self::assertStringNotContainsString('Commune entière', $html);
        self::assertStringNotContainsString($this->w->guestB->name(), $html);
        self::assertStringNotContainsString('Consignes', $html);
    }

    public function testUnknownMalformedAndSessionlessLinksRevealNothing(): void
    {
        $this->referential();
        $this->invited();
        $this->visitor();

        foreach (['A'.str_repeat('b', 42), str_repeat('0', 43)] as $forged) {
            $html = $this->open($forged);
            self::assertSame(404, $this->httpStatus());
            self::assertStringContainsString('Lien introuvable', $html);
            self::assertStringNotContainsString('Incendie', $html);
            self::assertStringNotContainsString($this->w->org->name(), $html);
        }
        $this->client->request('GET', '/invitation/trop-court');
        self::assertSame(404, $this->httpStatus());

        $this->client->request('GET', '/invitation');
        self::assertSame(404, $this->httpStatus(), 'aucune session de lien : aucune information');
        self::assertStringContainsString('Lien introuvable', (string) $this->client->getResponse()->getContent());
    }

    public function testAnExpiredLinkIsGoneButTheInvitationDoesNotExpireAndCanBeResent(): void
    {
        $this->referential();
        $token = $this->invited();
        $this->em()->getConnection()->executeStatement("UPDATE onboarding_invitation_link SET expires_at = '2020-01-01 00:00:00'");
        $this->visitor();

        $html = $this->open($token);

        self::assertSame(410, $this->httpStatus());
        self::assertStringContainsString('Ce lien a expiré', $html);
        self::assertStringContainsString('renvoyer', $html);
        self::assertStringNotContainsString('Incendie', $html);
        self::assertSame('INVITED', (string) $this->em()->getConnection()->fetchOne("SELECT p.status FROM event_participation p JOIN municipality m ON m.id = p.municipality_id WHERE m.insee_code = '33503'"), 'l’invitation (DM-086) n’expire pas');

        $participation = (string) $this->em()->getConnection()->fetchOne("SELECT BIN_TO_UUID(p.id) FROM event_participation p JOIN municipality m ON m.id = p.municipality_id WHERE m.insee_code = '33503'");
        static::getContainer()->get(InvitationDispatcher::class)->resend($this->user($this->w->adminO), $this->fire, $participation, null);
        $this->visitor();
        $this->open($this->mails->last('CONTACT'));
        self::assertSame(200, $this->httpStatus(), 'le nouveau lien fonctionne');
    }

    public function testAReplacedLinkIsGoneAndOnlyTheLatestWorks(): void
    {
        $this->referential();
        $old = $this->invited();
        $participation = (string) $this->em()->getConnection()->fetchOne("SELECT BIN_TO_UUID(p.id) FROM event_participation p JOIN municipality m ON m.id = p.municipality_id WHERE m.insee_code = '33503'");
        static::getContainer()->get(InvitationDispatcher::class)->resend($this->user($this->w->adminO), $this->fire, $participation, null);
        $new = $this->mails->last('CONTACT');
        $this->visitor();

        $html = $this->open($old);
        self::assertSame(410, $this->httpStatus());
        self::assertStringContainsString('remplacé', $html);

        $this->visitor();
        $this->open($new);
        self::assertSame(200, $this->httpStatus());
    }

    public function testALinkOfAClosedEventIsInactiveAndShowsNothingAboutTheEvent(): void
    {
        $this->referential();
        $token = $this->invited();
        static::getContainer()->get(EventLifecycleService::class)->close($this->user($this->w->adminO), $this->fire);
        $this->visitor();

        $html = $this->open($token);

        self::assertSame(410, $this->httpStatus());
        self::assertStringContainsString('n’est plus en attente', $html);
        self::assertStringNotContainsString('Incendie', $html);
    }

    // ═══ Commune déjà administrée : parcours existant ═══════════════════════

    public function testAContactLinkOfACommuneThatAlreadyHasAnAdministratorOnlyOffersTheExistingLogin(): void
    {
        $token = $this->invited($this->w->guestA->inseeCode()->value);
        $admin = UserBuilder::aUser()->build();
        $this->em()->persist($admin);
        $this->em()->flush();
        static::getContainer()->get(MembershipManager::class)->grant($admin, $this->em()->find(Municipality::class, $this->w->guestA->id()) ?? self::fail(), [MunicipalityRole::MunicipalityAdmin], null);
        $this->visitor();

        $html = $this->open($token);

        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('dispose déjà d’un administrateur', $html);
        self::assertStringContainsString('href="/login"', $html);
        self::assertStringNotContainsString('name="administrator_email"', $html, 'aucune délégation possible');

        $this->client->request('POST', '/invitation/delegation', ['administrator_email' => 'intrus@mairie.example', '_token' => 'x']);
        self::assertContains($this->httpStatus(), [409, 422]);
        self::assertSame(0, $this->links("kind = 'ADMINISTRATOR'"));
    }

    // ═══ Délégation de l'administrateur communal ════════════════════════════

    public function testTheContactDesignatesAnAdministratorWhoReceivesAPersonalLinkAndNothingElseChanges(): void
    {
        $this->referential();
        $contactToken = $this->invited(email: 'secretariat@mairie.example');
        $this->visitor();
        $users = $this->scalar('SELECT COUNT(*) FROM identity_user');

        $html = $this->open($contactToken);
        self::assertStringContainsString('n’a pas encore d’administrateur', $html);
        self::assertStringContainsString('name="administrator_email"', $html);
        self::assertStringContainsString('ne peut pas vérifier', $html, 'l’habilitation officielle n’est pas vérifiée : dit clairement');
        $this->client->request('POST', '/invitation/delegation', ['administrator_email' => 'Directrice@Mairie.example', '_token' => $this->csrf($html, '/invitation/delegation')]);

        self::assertSame(303, $this->httpStatus());
        self::assertSame(1, $this->links("kind = 'ADMINISTRATOR' AND revoked_at IS NULL AND consumed_at IS NULL"));
        $mail = $this->mails->ofKind('ADMINISTRATOR')[0];
        self::assertSame('directrice@mairie.example', $mail['to'], 'adresse normalisée, quelconque');
        self::assertSame('s***@mairie.example', $mail['maskedContact'], 'l’origine est reconnaissable sans exposer l’adresse');
        self::assertSame($users, $this->scalar('SELECT COUNT(*) FROM identity_user'), 'aucun compte créé par la délégation');
        self::assertSame(0, $this->memberships('33503'), 'aucun droit accordé par la délégation');
        self::assertSame(1, $this->audits('INVITATION_ADMINISTRATOR_DELEGATED'));
        // Le lien de contact reste utilisable (adresse à corriger) et la confirmation masque l'adresse de la personne désignée.
        $confirmation = $this->get('/invitation');
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('d***@mairie.example', $confirmation);
    }

    public function testDelegatingAgainReplacesThePreviousPersonalLink(): void
    {
        $this->referential();
        $contactToken = $this->invited();
        $this->visitor();
        $this->delegate($contactToken, 'premiere@mairie.example');
        $first = $this->mails->last('ADMINISTRATOR');
        $this->delegate($contactToken, 'seconde@mairie.example');
        $second = $this->mails->last('ADMINISTRATOR');

        $this->visitor();
        $this->open($first);
        self::assertSame(410, $this->httpStatus(), 'le premier lien personnel est remplacé');
        $this->visitor();
        $this->open($second);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(1, $this->links("kind = 'ADMINISTRATOR' AND revoked_at IS NULL"));
    }

    public function testInvalidDelegationsAreRefusedWithoutAnyLink(): void
    {
        $this->referential();
        $contactToken = $this->invited();
        $this->visitor();
        $html = $this->open($contactToken);
        $token = $this->csrf($html, '/invitation/delegation');

        foreach ([['', $token], ['pas-une-adresse', $token], ['ok@mairie.example', 'forge']] as [$email, $csrf]) {
            $this->client->request('POST', '/invitation/delegation', ['administrator_email' => $email, '_token' => $csrf]);
            self::assertSame(422, $this->httpStatus(), $email.'/'.$csrf);
        }
        self::assertSame(0, $this->links("kind = 'ADMINISTRATOR'"));
        self::assertSame([], $this->mails->ofKind('ADMINISTRATOR'));
    }

    public function testTheDelegationIsRateLimitedPerLink(): void
    {
        $this->referential();
        $contactToken = $this->invited();
        $this->visitor();
        $html = $this->open($contactToken);
        $token = $this->csrf($html, '/invitation/delegation');

        for ($i = 1; $i <= 5; ++$i) {
            $this->client->request('POST', '/invitation/delegation', ['administrator_email' => 'p'.$i.'@mairie.example', '_token' => $token]);
            self::assertSame(303, $this->httpStatus(), 'envoi '.$i);
        }
        $this->client->request('POST', '/invitation/delegation', ['administrator_email' => 'p6@mairie.example', '_token' => $token]);

        self::assertSame(429, $this->httpStatus());
        self::assertSame(5, \count($this->mails->ofKind('ADMINISTRATOR')));
    }

    public function testLinksOfTheWrongKindCannotBeUsedForTheOtherAction(): void
    {
        $this->referential();
        [$adminToken] = $this->adminLink();
        $this->visitor();

        // Lien personnel utilisé pour déléguer : refusé.
        $html = $this->open($adminToken);
        $this->client->request('POST', '/invitation/delegation', ['administrator_email' => 'x@mairie.example', '_token' => $this->csrf($html, '/invitation/rejoindre')]);
        self::assertContains($this->httpStatus(), [409, 422]);
        self::assertSame(1, $this->links("kind = 'ADMINISTRATOR'"), 'aucun lien supplémentaire');

        // Lien de contact utilisé pour s'inscrire administrateur : refusé, aucun compte, aucun droit.
        $this->visitor();
        $contactToken = $this->mails->ofKind('CONTACT')[0]['token'];
        $html = $this->open($contactToken);
        $this->client->request('POST', '/invitation/rejoindre', $this->signup() + ['_token' => $this->csrf($html, '/invitation/delegation')]);
        self::assertContains($this->httpStatus(), [409, 422]);
        self::assertSame(0, $this->memberships('33503'));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM identity_user WHERE email = 'directrice@mairie.example'"));
    }

    // ═══ Inscription de l'administrateur par lien personnel ═════════════════

    public function testANewAdministratorCreatesAnAccountBecomesTheOnlyAdministratorAndThenAcceptsExplicitly(): void
    {
        $this->referential();
        [$adminToken, $email] = $this->adminLink();
        $this->visitor();
        $access = $this->scalar('SELECT COUNT(*) FROM municipality_commercial_access');

        $html = $this->open($adminToken);
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('name="first_name"', $html);
        self::assertStringContainsString('name="password"', $html);
        self::assertStringContainsString('d***@mairie.example', $html);
        self::assertStringContainsString('ne prouve pas à lui seul votre habilitation officielle', $html);

        $this->join($html, $this->signup());

        self::assertSame(303, $this->httpStatus());
        $communeId = $this->communeId('33503');
        self::assertStringContainsString('/communes/'.$communeId.'/evenements/', $this->location());
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $email]) ?? self::fail('compte non créé');
        self::assertSame('directrice@mairie.example', $user->email(), 'adresse du lien, non saisie');
        $roles = (string) $this->em()->getConnection()->fetchOne('SELECT roles FROM municipality_membership WHERE user_id = ?', [$user->id()->toBinary()]);
        self::assertSame(['MUNICIPALITY_ADMIN'], json_decode($roles, true), 'rôle administrateur UNIQUEMENT');
        self::assertSame(1, $this->memberships('33503'));
        self::assertSame($access, $this->scalar('SELECT COUNT(*) FROM municipality_commercial_access'), 'aucun abonnement ni droit commercial');
        self::assertSame(1, $this->links('consumed_at IS NOT NULL'));
        self::assertSame(1, $this->audits('INVITATION_ADMINISTRATOR_ENROLLED'));
        $audit = (string) $this->em()->getConnection()->fetchOne("SELECT new_value FROM audit_log WHERE action = 'INVITATION_ADMINISTRATOR_ENROLLED'");
        self::assertEquals(['attestedRepresentation' => true, 'emailControlProven' => true, 'officialHabilitationVerified' => false], array_intersect_key(json_decode($audit, true), ['attestedRepresentation' => 1, 'emailControlProven' => 1, 'officialHabilitationVerified' => 1]));

        // Connecté d'office, l'invitation est toujours en attente : l'acceptation reste une action explicite.
        $page = $this->get($this->location());
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('Accepter l’invitation', $page);
        self::assertSame('INVITED', (string) $this->em()->getConnection()->fetchOne("SELECT p.status FROM event_participation p JOIN municipality m ON m.id = p.municipality_id WHERE m.insee_code = '33503'"));

        $this->client->request('POST', $this->eventUrl($communeId, $this->fire).'/participation/accepter', ['_token' => $this->csrf($page, '/participation/accepter')]);
        self::assertSame(303, $this->httpStatus());
        self::assertSame('ACTIVE', (string) $this->em()->getConnection()->fetchOne("SELECT p.status FROM event_participation p JOIN municipality m ON m.id = p.municipality_id WHERE m.insee_code = '33503'"));
        self::assertFalse(static::getContainer()->get(EventCreationPolicy::class)->canCreateEvent($this->em()->find(Municipality::class, $communeId) ?? self::fail(), EventFixtures::now()), 'aucun droit de créer ses propres événements');
    }

    public function testAPersonalLinkWorksOnlyOnce(): void
    {
        $this->referential();
        [$adminToken] = $this->adminLink();
        $this->visitor();
        $html = $this->open($adminToken);
        $this->join($html, $this->signup());
        self::assertSame(303, $this->httpStatus());

        $this->visitor();
        $again = $this->open($adminToken);

        self::assertSame(410, $this->httpStatus());
        self::assertStringContainsString('déjà été utilisé', $again);
        self::assertSame(1, $this->memberships('33503'));
    }

    public function testWithoutTheRepresentationAttestationNothingIsCreated(): void
    {
        $this->referential();
        [$adminToken] = $this->adminLink();
        $this->visitor();
        $html = $this->open($adminToken);

        $html = $this->join($html, $this->signup(attest: ''));

        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString('habilité(e) à représenter', $html);
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM identity_user WHERE email = 'directrice@mairie.example'"));
        self::assertSame(0, $this->memberships('33503'));
        self::assertSame(0, $this->links('consumed_at IS NOT NULL'), 'lien non consommé');
    }

    public function testAWeakOrInconsistentPasswordCreatesNothingAndKeepsTheLinkUsable(): void
    {
        $this->referential();
        [$adminToken] = $this->adminLink();
        $this->visitor();
        $html = $this->open($adminToken);

        $this->join($html, $this->signup('court'));
        $weak = $this->httpStatus();
        $this->join($html, ['password_confirm' => 'autre chose de long'] + $this->signup());
        self::assertSame([422, 422], [$weak, $this->httpStatus()]);

        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM identity_user WHERE email = 'directrice@mairie.example'"));
        self::assertSame(0, $this->memberships('33503'));
        $this->visitor();
        $this->open($adminToken);
        self::assertSame(200, $this->httpStatus(), 'le lien reste utilisable');
    }

    public function testAnExistingAccountMustLogInWithTheInvitedAddressThenConfirms(): void
    {
        $this->referential();
        $existing = UserBuilder::aUser()->withEmail('directrice@mairie.example')->build();
        $this->em()->persist($existing);
        $this->em()->flush();
        $hash = (string) $this->em()->getConnection()->fetchOne('SELECT password FROM identity_user WHERE email = ?', ['directrice@mairie.example']);
        [$adminToken] = $this->adminLink();
        $this->visitor();

        $html = $this->open($adminToken);
        self::assertStringContainsString('Un compte Civipass existe déjà', $html);
        self::assertStringContainsString('href="/login"', $html);
        self::assertStringNotContainsString('name="password"', $html, 'pas de création de compte pour une adresse existante');
        self::assertSame(0, $this->memberships('33503'), 'la possession du lien ne rattache jamais un compte existant');

        $this->loginAs($existing);
        $this->client->request('GET', '/invitation');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('J’atteste être habilité(e)', $html);
        self::assertStringNotContainsString('name="password"', $html);

        $this->join($html, ['attest' => '1']);

        self::assertSame(303, $this->httpStatus());
        self::assertSame(1, $this->memberships('33503'));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM identity_user WHERE email = 'directrice@mairie.example'"), 'aucun doublon de compte');
        self::assertSame($hash, (string) $this->em()->getConnection()->fetchOne('SELECT password FROM identity_user WHERE email = ?', ['directrice@mairie.example']), 'mot de passe inchangé');
    }

    public function testAnotherAccountCannotUseThePersonalLink(): void
    {
        $this->referential();
        [$adminToken] = $this->adminLink();
        $this->visitor();
        $this->open($adminToken);

        $this->loginAs($this->w->adminX); // compte d'une autre commune, connecté avec le lien d'une autre adresse
        $this->client->request('GET', '/invitation');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('autre compte', $html);
        self::assertStringNotContainsString('J’atteste', $html);

        $this->client->request('POST', '/invitation/rejoindre', ['attest' => '1', '_token' => 'forgé']);
        self::assertContains($this->httpStatus(), [409, 422]);
        self::assertSame(0, $this->memberships('33503'), 'aucun droit accordé à un autre compte');
        self::assertSame(0, $this->links('consumed_at IS NOT NULL'));
    }

    public function testALinkNeverGivesRightsOverACommuneThatIsAlreadyAdministeredByAnotherPerson(): void
    {
        $this->referential();
        [$adminToken] = $this->adminLink();
        // Entre-temps, un administrateur légitime est créé par la voie habituelle (superadmin / console).
        $legit = UserBuilder::aUser()->build();
        $this->em()->persist($legit);
        $this->em()->flush();
        static::getContainer()->get(MembershipManager::class)->grant($legit, $this->em()->find(Municipality::class, $this->communeId('33503')) ?? self::fail(), [MunicipalityRole::MunicipalityAdmin], null);
        $users = $this->scalar('SELECT COUNT(*) FROM identity_user');
        $this->visitor();

        $html = $this->open($adminToken);
        self::assertStringContainsString('dispose déjà d’un administrateur', $html);
        self::assertStringContainsString('Ce lien ne donne aucun droit', $html);
        self::assertStringNotContainsString('name="password"', $html);

        $this->client->request('POST', '/invitation/rejoindre', $this->signup() + ['_token' => 'forgé']);
        self::assertContains($this->httpStatus(), [409, 422]);
        self::assertSame(1, $this->memberships('33503'), 'seul l’administrateur légitime');
        self::assertSame($users, $this->scalar('SELECT COUNT(*) FROM identity_user'), 'aucun compte créé');
    }

    public function testTwoPersonalLinksForTheSameCommuneOnlyTheFirstEnrolls(): void
    {
        $this->referential();
        $this->invited('33503', 'a@mairie.example', $this->fire);
        $this->delegate($this->mails->last('CONTACT'), 'premier@mairie.example');
        $first = $this->mails->last('ADMINISTRATOR');
        // Même commune invitée à un second événement du même organisateur : un second lien personnel valide existe.
        $this->invited('33503', 'b@mairie.example', $this->accident);
        $this->delegate($this->mails->last('CONTACT'), 'second@mairie.example');
        $second = $this->mails->last('ADMINISTRATOR');

        $this->visitor();
        $this->join($this->open($first), $this->signup());
        self::assertSame(303, $this->httpStatus());

        $this->visitor();
        $html = $this->open($second);
        self::assertStringContainsString('dispose déjà d’un administrateur', $html);
        $this->client->request('POST', '/invitation/rejoindre', $this->signup() + ['_token' => 'forgé']);
        self::assertContains($this->httpStatus(), [409, 422]);
        self::assertSame(1, $this->memberships('33503'), 'un seul administrateur, jamais deux par deux liens');
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM identity_user WHERE email = 'second@mairie.example'"));
    }

    public function testALinkOfOneCommuneNeverGivesAccessToAnother(): void
    {
        $this->referential();
        $this->referential('33504', 'Autre commune');
        [$adminToken] = $this->adminLink('33503', 'directrice@mairie.example');
        $this->delegate($this->invited('33504', 'c@mairie.example'), 'autre@mairie.example');
        $this->visitor();

        $this->join($this->open($adminToken), $this->signup());

        self::assertSame(1, $this->memberships('33503'));
        self::assertSame(0, $this->memberships('33504'), 'le lien est lié à SA participation, donc à SA commune');
    }

    public function testOpeningLinksIsRateLimitedPerIp(): void
    {
        $this->visitor();
        for ($i = 0; $i < 30; ++$i) {
            $this->client->request('GET', '/invitation/'.str_repeat('a', 43));
        }

        $this->client->request('GET', '/invitation/'.str_repeat('a', 43));

        self::assertSame(429, $this->httpStatus());
        self::assertStringContainsString('Trop de tentatives', (string) $this->client->getResponse()->getContent());
    }

    public function testEveryInvitationPageIsNotCachedAndSentWithoutReferrer(): void
    {
        $this->referential();
        $token = $this->invited();
        $this->visitor();

        foreach ([['GET', '/invitation/'.str_repeat('z', 43)], ['GET', '/invitation/'.$token], ['GET', '/invitation'], ['GET', '/invitation']] as [$method, $url]) {
            $this->client->request($method, $url);
            $headers = $this->client->getResponse()->headers;
            self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'), $url);
            self::assertSame('no-referrer', $headers->get('Referrer-Policy'), $url);
            self::assertStringContainsString('noindex', (string) $headers->get('X-Robots-Tag'), $url);
        }
    }
}
