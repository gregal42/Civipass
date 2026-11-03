<?php

declare(strict_types=1);

namespace App\Tests\Functional\Event;

use App\Event\Application\Command\ZoneValidityCommand;
use App\Event\Application\Service\EventLifecycleService;
use App\Event\Application\Service\EventZoneService;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityCommercialAccess;
use App\Municipality\Domain\MunicipalityRole;
use App\Tests\Support\EventFixtures;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\MunicipalPagesTestCase;
use App\Tests\Support\UserBuilder;

/**
 * Création et modification d'un événement par l'interface (MySQL de test) : les données sont réellement écrites par les
 * services TECH-02.1 ; ces tests vérifient le câblage HTTP, pas les règles métier déjà couvertes par TECH-02.1.
 */
final class EventFormPageTest extends MunicipalPagesTestCase
{
    private function newUrl(Municipality|string $municipality): string
    {
        return $this->listUrl($municipality).'/nouveau';
    }

    private function editUrl(Municipality|string $municipality, string $eventId): string
    {
        return $this->eventUrl($municipality, $eventId).'/modifier';
    }

    /**
     * @param array<string, string> $override
     *
     * @return array<string, string>
     */
    private function validCreate(array $override = []): array
    {
        return $override + [
            'name' => '  Incendie de la forêt de Lande  ',
            'type' => 'fire',
            'description' => 'Feu de forêt au nord du massif',
            'instructions' => 'Route départementale fermée',
            'start_date' => '2026-12-01',
            'start_time' => '09:00',
            'end_date' => '2026-12-03',
            'end_time' => '18:30',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(string $name): ?array
    {
        $row = $this->em()->getConnection()->fetchAssociative('SELECT * FROM event_event WHERE name = ?', [$name]);

        return false === $row ? null : $row;
    }

    private function eventCount(): int
    {
        return $this->scalar('SELECT COUNT(*) FROM event_event');
    }

    private function auditCount(string $action): int
    {
        return $this->scalar('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action]);
    }

    private function activate(string $eventId): void
    {
        $admin = $this->user($this->w->adminO);
        $zone = (string) $this->em()->getConnection()->fetchOne('SELECT LOWER(CONCAT(SUBSTR(HEX(z.id),1,8),\'-\',SUBSTR(HEX(z.id),9,4),\'-\',SUBSTR(HEX(z.id),13,4),\'-\',SUBSTR(HEX(z.id),17,4),\'-\',SUBSTR(HEX(z.id),21))) FROM event_zone z JOIN event_participation p ON p.id = z.participation_id WHERE p.event_id = ? AND p.municipality_id = ?', [(string) hex2bin(str_replace('-', '', $eventId)), $this->w->org->id()->toBinary()]);
        $zones = static::getContainer()->get(EventZoneService::class);
        $zones->confirm($admin, $zone);
        $zones->configureValidity($admin, $zone, new ZoneValidityCommand('EVENT_DURATION'));
        static::getContainer()->get(EventLifecycleService::class)->activate($admin, $eventId);
        $this->em()->clear();
    }

    // ═══ Création : formulaire ═══════════════════════════════════════════════

    public function testTheCreationFormOffersTheExpectedFieldsAndNoReference(): void
    {
        $this->loginAs($this->w->adminO);

        $html = $this->get($this->newUrl($this->w->org));

        self::assertSame(200, $this->httpStatus());
        foreach (['name="name"', 'name="type"', 'name="description"', 'name="instructions"', 'name="start_date"', 'name="start_time"', 'name="end_date"', 'name="end_time"', 'name="_token"'] as $field) {
            self::assertStringContainsString($field, $html, $field);
        }
        self::assertStringContainsString('Europe/Paris', $html, 'fuseau déduit de la commune');
        self::assertStringNotContainsString('name="timezone"', $html, 'déduit : aucun champ à saisir');
        self::assertStringNotContainsString('name="reference"', $html, 'la référence n\'est jamais saisie');
        self::assertStringContainsString($this->w->org->name(), $html);
        self::assertStringContainsString('Incendie', $html, 'types du référentiel');
    }

    // ═══ Création : succès ═══════════════════════════════════════════════════

    public function testCreationPersistsThroughTheServicesAndRedirectsToTheRecord(): void
    {
        $this->loginAs($this->w->adminO);
        $before = $this->eventCount();

        $this->submit($this->newUrl($this->w->org), $this->validCreate());

        self::assertSame(303, $this->httpStatus());
        self::assertSame($before + 1, $this->eventCount());
        $row = $this->row('Incendie de la forêt de Lande') ?? self::fail('Événement non enregistré.');
        self::assertSame('DRAFT', $row['status']);
        self::assertSame('Europe/Paris', $row['timezone']);
        self::assertSame('2026-12-01 08:00:00', $row['starts_at'], '09:00 à Paris = 08:00 UTC');
        self::assertSame('2026-12-03 17:30:00', $row['ends_at']);
        self::assertSame('Feu de forêt au nord du massif', $row['description']);
        self::assertSame('Route départementale fermée', $row['operational_instructions']);
        self::assertMatchesRegularExpression('/^EVT-\d{4}-\d{6}$/', (string) $row['public_reference']);
        self::assertSame($this->w->adminO->id()->toBinary(), $row['created_by']);
        self::assertSame($this->w->org->id()->toBinary(), $row['organizer_municipality_id']);

        $eventId = \Symfony\Component\Uid\Uuid::fromBinary($row['id'])->toRfc4122();
        self::assertSame($this->eventUrl($this->w->org, $eventId), parse_url($this->location(), \PHP_URL_PATH));
        $page = $this->get($this->eventUrl($this->w->org, $eventId));
        self::assertStringContainsString('créé en brouillon', $page, 'message de confirmation');
        self::assertStringContainsString((string) $row['public_reference'], $page, 'référence générée affichée');
        self::assertStringContainsString('Incendie de la forêt de Lande', $page);
        self::assertStringNotContainsString('créé en brouillon', $this->get($this->eventUrl($this->w->org, $eventId)), 'le message ne s\'affiche qu\'une fois');
        self::assertStringContainsString('Incendie de la forêt de Lande', $this->get($this->listUrl($this->w->org, '?q=forêt')), 'présent dans la liste');
    }

    public function testCreationWritesTheTransactionalAudit(): void
    {
        $this->loginAs($this->w->adminO);
        $audits = $this->scalar('SELECT COUNT(*) FROM audit_log');

        $this->submit($this->newUrl($this->w->org), $this->validCreate());

        self::assertSame($audits + 3, $this->scalar('SELECT COUNT(*) FROM audit_log'), 'événement, participation organisatrice, zone proposée');
        self::assertSame(1, $this->scalar("SELECT COUNT(DISTINCT batch_id) FROM (SELECT batch_id FROM audit_log WHERE action IN ('EVENT_CREATED','PARTICIPATION_CREATED','ZONE_CREATED') ORDER BY id DESC LIMIT 3) t"), 'un seul lot d\'audit');
        self::assertSame($this->w->adminO->id()->toBinary(), $this->em()->getConnection()->fetchOne("SELECT actor_id FROM audit_log WHERE action = 'EVENT_CREATED' ORDER BY id DESC LIMIT 1"));
        self::assertStringNotContainsString('Incendie de la forêt', (string) $this->em()->getConnection()->fetchOne("SELECT new_value FROM audit_log WHERE action = 'EVENT_CREATED' ORDER BY id DESC LIMIT 1"), 'pas de texte libre dans l\'audit');
    }

    public function testForgedFieldsCannotChangeTheOrganizerTheReferenceTheStatusOrTheResolvedTimezone(): void
    {
        $this->loginAs($this->w->adminO);

        $this->submit($this->newUrl($this->w->org), $this->validCreate([
            'reference' => 'EVT-1999-000001',
            'municipality_id' => $this->id($this->w->guestB),
            'organizer' => $this->id($this->w->guestB),
            'status' => 'ACTIVE',
            'timezone' => 'Pacific/Tahiti',
            'created_by' => $this->id($this->w->guestB),
        ]));

        self::assertSame(303, $this->httpStatus());
        $row = $this->row('Incendie de la forêt de Lande') ?? self::fail();
        self::assertSame($this->w->org->id()->toBinary(), $row['organizer_municipality_id']);
        self::assertNotSame('EVT-1999-000001', $row['public_reference']);
        self::assertSame('DRAFT', $row['status']);
        self::assertSame('Europe/Paris', $row['timezone'], 'fuseau déduit de la commune : la valeur du client est ignorée');
        self::assertSame($this->w->adminO->id()->toBinary(), $row['created_by']);
    }

    // ═══ Création : validation ═══════════════════════════════════════════════

    public function testValidationErrorsReturn422KeepTheInputAndWriteNothing(): void
    {
        $this->loginAs($this->w->adminO);
        $events = $this->eventCount();
        $audits = $this->scalar('SELECT COUNT(*) FROM audit_log');

        $html = $this->submit($this->newUrl($this->w->org), ['name' => '   ', 'type' => '', 'description' => 'Saisie à conserver', 'instructions' => 'Consigne à conserver', 'start_date' => '', 'start_time' => '', 'end_date' => '2026-12-03', 'end_time' => '']);

        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString('Corrigez ces points', $html);
        foreach (['Indiquez le nom', 'Choisissez le type', 'Indiquez la date de début', 'Indiquez l’heure de début', 'Indiquez l’heure de fin'] as $message) {
            self::assertStringContainsString($message, $html, $message);
        }
        self::assertStringContainsString('aria-invalid="true"', $html, 'champs incorrects identifiés');
        self::assertStringContainsString('Saisie à conserver', $html, 'saisies conservées');
        self::assertStringContainsString('Consigne à conserver', $html);
        self::assertStringContainsString('value="2026-12-03"', $html);
        self::assertSame($events, $this->eventCount());
        self::assertSame($audits, $this->scalar('SELECT COUNT(*) FROM audit_log'));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'date inexistante' => [['start_date' => '2026-02-30'], 'Date invalide'];
        yield 'date mal formée' => [['start_date' => 'demain'], 'Date invalide'];
        yield 'heure mal formée' => [['start_time' => '25:99'], 'Heure invalide'];
        yield 'type inconnu' => [['type' => 'meteorite'], 'pas proposé'];
        yield 'nom trop long' => [['name' => 'x', 'name_long' => '1'], 'caractères'];
        yield 'fin sans heure' => [['end_time' => ''], 'Indiquez l’heure de fin'];
        yield 'fin avant début (règle du domaine)' => [['end_date' => '2026-11-30', 'end_time' => '10:00'], 'postérieure'];
        yield 'fin égale au début (règle du domaine)' => [['end_date' => '2026-12-01', 'end_time' => '09:00'], 'postérieure'];
    }

    /**
     * @param array<string, string> $override
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInputs')]
    public function testInvalidInputsAreRefusedWith422AndWithoutAnyWrite(array $override, string $expected): void
    {
        $this->loginAs($this->w->adminO);
        $events = $this->eventCount();
        if (isset($override['name_long'])) {
            $override = ['name' => str_repeat('é', 201)];
        }

        $html = $this->submit($this->newUrl($this->w->org), $this->validCreate($override));

        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString($expected, $html);
        self::assertStringContainsString('Feu de forêt au nord du massif', $html, 'saisies conservées');
        self::assertSame($events, $this->eventCount());
    }

    public function testArrayInjectionIsTreatedAsAnEmptyValue(): void
    {
        $this->loginAs($this->w->adminO);
        $events = $this->eventCount();

        $html = $this->submit($this->newUrl($this->w->org), ['name' => ['x'], 'type' => ['fire'], 'start_date' => ['2026-12-01'], 'start_time' => '09:00']);

        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString('Indiquez le nom', $html);
        self::assertSame($events, $this->eventCount());
    }

    public function testNonexistentAndRepeatedLocalTimesFollowTheDstRules(): void
    {
        $this->loginAs($this->w->adminO);
        $url = $this->newUrl($this->w->org);

        $html = $this->submit($url, $this->validCreate(['name' => 'Heure inexistante', 'start_date' => '2027-03-28', 'start_time' => '02:30', 'end_date' => '', 'end_time' => '']));
        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString('existe pas', $html);
        self::assertStringContainsString('02:30', $html);
        self::assertNull($this->row('Heure inexistante'), 'aucune correction silencieuse');

        $html = $this->submit($url, $this->validCreate(['name' => 'Heure répétée', 'start_date' => '2026-10-25', 'start_time' => '02:30', 'end_date' => '', 'end_time' => '']));
        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString('se produit deux fois', $html);
        self::assertStringContainsString('name="start_occurrence"', $html, 'choix explicite proposé');
        self::assertStringContainsString('Première occurrence (UTC+02:00)', $html);
        self::assertStringContainsString('Seconde occurrence (UTC+01:00)', $html);
        self::assertNull($this->row('Heure répétée'));

        $this->submit($url, $this->validCreate(['name' => 'Heure répétée', 'start_date' => '2026-10-25', 'start_time' => '02:30', 'end_date' => '', 'end_time' => '', 'start_occurrence' => 'second']));
        self::assertSame(303, $this->httpStatus());
        self::assertSame('2026-10-25 01:30:00', ($this->row('Heure répétée') ?? self::fail())['starts_at'], 'seconde occurrence : UTC+1');

        $this->submit($url, $this->validCreate(['name' => 'Heure répétée 1', 'start_date' => '2026-10-25', 'start_time' => '02:30', 'end_date' => '', 'end_time' => '', 'start_occurrence' => 'first']));
        self::assertSame('2026-10-25 00:30:00', ($this->row('Heure répétée 1') ?? self::fail())['starts_at'], 'première occurrence : UTC+2');
    }

    public function testTheSummerOffsetIsAppliedToTheStoredInstant(): void
    {
        $this->loginAs($this->w->adminO);

        $this->submit($this->newUrl($this->w->org), $this->validCreate(['name' => 'Événement d\'été', 'start_date' => '2027-07-14', 'start_time' => '09:00', 'end_date' => '', 'end_time' => '']));

        self::assertSame('2027-07-14 07:00:00', ($this->row('Événement d\'été') ?? self::fail())['starts_at'], '09:00 à Paris en été = 07:00 UTC');
        self::assertNull(($this->row('Événement d\'été') ?? self::fail())['ends_at']);
    }

    public function testAMunicipalityWhoseTimezoneCannotBeDeducedMustChooseOne(): void
    {
        $now = EventFixtures::now();
        $municipality = MunicipalityBuilder::aMunicipality()->withInseeCode('98755')->build();
        $admin = UserBuilder::aUser()->build();
        $this->em()->persist($municipality);
        $this->em()->persist($admin);
        $this->em()->persist(MembershipBuilder::aMembership($admin, $municipality)->withRoles(MunicipalityRole::MunicipalityAdmin)->build());
        $this->em()->persist(new MunicipalityCommercialAccess($municipality, $this->user($this->w->superAdmin), $now->modify('-30 days'), null, 'Convention CWS', $now->modify('-30 days')));
        $this->em()->flush();
        $this->loginAs($admin);
        $url = $this->newUrl($municipality);

        $html = $this->get($url);
        self::assertStringContainsString('name="timezone"', $html, 'sélection explicite');
        self::assertStringContainsString('Pacific/Tahiti', $html);
        self::assertStringContainsString('ne peut pas être déterminé', $html);

        $html = $this->submit($url, $this->validCreate(['name' => 'Événement polynésien']));
        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString('Choisissez le fuseau horaire', $html);

        $html = $this->submit($url, $this->validCreate(['name' => 'Événement polynésien', 'timezone' => 'Mars/Olympus']));
        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString('pas pris en charge', $html);
        self::assertNull($this->row('Événement polynésien'));

        $this->submit($url, $this->validCreate(['name' => 'Événement polynésien', 'timezone' => 'Pacific/Tahiti']));
        self::assertSame(303, $this->httpStatus());
        $row = $this->row('Événement polynésien') ?? self::fail();
        self::assertSame('Pacific/Tahiti', $row['timezone']);
        self::assertSame('2026-12-01 19:00:00', $row['starts_at'], '09:00 à Tahiti (UTC-10) = 19:00 UTC');
    }

    // ═══ Création : CSRF ═════════════════════════════════════════════════════

    public function testCsrfProtectionRefusesTheWriteAndKeepsTheInput(): void
    {
        $this->loginAs($this->w->adminO);
        $events = $this->eventCount();
        $url = $this->newUrl($this->w->org);

        $this->client->request('POST', $url, $this->validCreate());
        self::assertSame(422, $this->httpStatus(), 'sans jeton');
        $this->client->request('POST', $url, $this->validCreate() + ['_token' => 'forgé']);
        self::assertSame(422, $this->httpStatus(), 'jeton forgé');
        $this->client->request('POST', $url, $this->validCreate() + ['_token' => ['x']]);
        self::assertSame(422, $this->httpStatus(), 'jeton tableau');
        $html = $this->submit($url, $this->validCreate(), $this->token($this->editUrl($this->w->org, $this->fire)));
        self::assertSame(422, $this->httpStatus(), 'jeton d\'un autre formulaire');
        self::assertStringContainsString('Le formulaire a expiré', $html);
        self::assertStringContainsString('Feu de forêt au nord du massif', $html, 'saisies conservées');
        self::assertSame($events, $this->eventCount());
    }

    // ═══ Création : permissions ══════════════════════════════════════════════

    public function testCreationIsRefusedToRolesWithoutTheRightAndWritesNothing(): void
    {
        $events = $this->eventCount();
        $cases = [
            'instructeur de la commune' => [$this->w->instructorO, $this->w->org],
            'commune sans droit d\'utilisation' => [$this->w->adminB, $this->w->guestB],
        ];
        foreach ($cases as $label => [$user, $municipality]) {
            $this->loginAs($user);
            $this->get($this->newUrl($municipality));
            self::assertSame(403, $this->httpStatus(), $label.' (GET)');
            $this->client->request('POST', $this->newUrl($municipality), $this->validCreate() + ['_token' => 'x']);
            self::assertSame(403, $this->httpStatus(), $label.' (POST)');
        }
        self::assertSame($events, $this->eventCount());
    }

    public function testASuspendedMunicipalityCannotCreateAndAnInactiveOneCannotEvenSeeTheForm(): void
    {
        $this->em()->getConnection()->executeStatement("UPDATE municipality SET status = 'SUSPENDED' WHERE id = ?", [$this->w->org->id()->toBinary()]);
        $this->loginAs($this->w->adminO);
        $this->get($this->newUrl($this->w->org));
        self::assertSame(403, $this->httpStatus());
        $this->client->request('POST', $this->newUrl($this->w->org), $this->validCreate() + ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        $this->em()->getConnection()->executeStatement("UPDATE municipality SET status = 'INACTIVE' WHERE id = ?", [$this->w->org->id()->toBinary()]);
        $this->loginAs($this->w->adminO);
        $this->get($this->newUrl($this->w->org));
        self::assertSame(404, $this->httpStatus());
    }

    public function testCreationWithoutAValidCommercialRightIsRefusedEvenWithAValidForm(): void
    {
        $this->em()->getConnection()->executeStatement('DELETE FROM municipality_commercial_access WHERE municipality_id = ?', [$this->w->org->id()->toBinary()]);
        $this->loginAs($this->w->adminO);
        $events = $this->eventCount();

        $this->get($this->newUrl($this->w->org));
        self::assertSame(403, $this->httpStatus());
        $this->client->request('POST', $this->newUrl($this->w->org), $this->validCreate() + ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($events, $this->eventCount());
        // La liste ne propose plus l'action et explique pourquoi.
        $list = $this->get($this->listUrl($this->w->org, '?tri=asc'));
        self::assertStringContainsString('droit d’utilisation', $list);
        self::assertStringNotContainsString('/evenements/nouveau"', $list);
    }

    public function testForeignForgedAndPrivilegelessAccountsCannotCreate(): void
    {
        $events = $this->eventCount();
        $this->loginAs($this->w->adminX);
        $this->get($this->newUrl($this->w->org));
        self::assertSame(404, $this->httpStatus(), 'identifiant de commune étranger');
        $this->client->request('POST', $this->newUrl($this->w->org), $this->validCreate() + ['_token' => 'x']);
        self::assertSame(404, $this->httpStatus());
        $this->get($this->newUrl(self::randomUuid()));
        self::assertSame(404, $this->httpStatus(), 'identifiant forgé');

        $this->loginAs($this->w->superAdmin);
        $this->get($this->newUrl($this->w->org));
        self::assertSame(404, $this->httpStatus(), 'aucun droit communal implicite');
        $this->client->request('POST', $this->newUrl($this->w->org), $this->validCreate() + ['_token' => 'x']);
        self::assertSame(404, $this->httpStatus());

        $this->em()->getConnection()->executeStatement('UPDATE identity_user SET active = 0 WHERE id = ?', [$this->w->adminO->id()->toBinary()]);
        $this->loginAs($this->w->adminO);
        $this->client->request('POST', $this->newUrl($this->w->org), $this->validCreate() + ['_token' => 'x']);
        self::assertNotSame(303, $this->httpStatus());
        self::assertSame($events, $this->eventCount());
    }

    public function testWriteFormsRequireAFullAuthentication(): void
    {
        $url = $this->newUrl($this->w->org);
        $this->client->request('GET', $url);
        self::assertResponseRedirects('http://localhost/login', 302, 'anonyme');
        $this->client->request('POST', $url, $this->validCreate());
        self::assertResponseRedirects('http://localhost/login', 302, 'anonyme (POST)');
        $this->client->request('GET', $this->editUrl($this->w->org, $this->fire));
        self::assertResponseRedirects('http://localhost/login', 302);

        // Connexion « se souvenir de moi » puis fin de session : la consultation reste possible, pas l'écriture.
        $account = $this->createAccount('remembered@example.test');
        $this->em()->persist(MembershipBuilder::aMembership($account, $this->em()->find(Municipality::class, $this->w->org->id()) ?? self::fail())->withRoles(MunicipalityRole::MunicipalityAdmin)->build());
        $this->em()->flush();
        $this->client->request('GET', '/login');
        $this->client->submitForm('Se connecter', ['email' => 'remembered@example.test', 'password' => self::PASSWORD, '_remember_me' => '1']);
        $remember = $this->client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($remember, 'précondition : cookie persistant');
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($remember);

        $this->get($this->listUrl($this->w->org));
        self::assertSame(200, $this->httpStatus(), 'consultation autorisée');
        $this->client->request('GET', $url);
        self::assertResponseRedirects('http://localhost/login', 302, 'écriture : réauthentification');
        $this->client->request('POST', $url, $this->validCreate() + ['_token' => 'x']);
        self::assertResponseRedirects('http://localhost/login', 302);
    }

    // ═══ Création : conflit transactionnel ═══════════════════════════════════

    public function testATransactionalConflictOnCreationIsReportedAndTheInputIsKept(): void
    {
        $this->loginAs($this->w->adminO);
        $token = $this->token($this->newUrl($this->w->org));
        $events = $this->eventCount();
        $other = $this->otherConnection();
        $other->beginTransaction();
        $other->executeStatement('UPDATE event_reference_counter SET last_number = last_number'); // verrou du compteur, tenu

        try {
            $html = $this->submit($this->newUrl($this->w->org), $this->validCreate(), $token);
        } finally {
            $other->rollBack();
        }

        self::assertSame(409, $this->httpStatus());
        self::assertStringContainsString('Un autre traitement est en cours', $html);
        self::assertStringContainsString('Rien n’a été enregistré', $html);
        self::assertStringContainsString('Feu de forêt au nord du massif', $html, 'saisies conservées');
        self::assertStringContainsString('name="_token"', $html, 'formulaire complet réaffiché malgré la transaction échouée');
        self::assertSame($events, $this->eventCount(), 'aucun succès annoncé, aucune écriture');

        // Nouvelle tentative manuelle : aboutit une fois le verrou libéré (jamais de retry automatique).
        $this->submit($this->newUrl($this->w->org), $this->validCreate());
        self::assertSame(303, $this->httpStatus());
        self::assertSame($events + 1, $this->eventCount());
    }

    // ═══ Modification : formulaire ═══════════════════════════════════════════

    public function testTheEditFormIsPrefilledWithTheEventInItsOwnTimezone(): void
    {
        foreach ([$this->w->adminO, $this->w->managerO] as $manager) {
            $this->loginAs($manager);
            $html = $this->get($this->editUrl($this->w->org, $this->fire));

            self::assertSame(200, $this->httpStatus());
            self::assertStringContainsString('value="Incendie du massif forestier"', $html);
            self::assertStringContainsString('value="2026-12-01"', $html);
            self::assertStringContainsString('value="09:00"', $html, 'heure locale du fuseau de l\'événement');
            self::assertStringContainsString('EVT-', $html, 'référence affichée en lecture seule');
            self::assertStringNotContainsString('name="reference"', $html);
            self::assertStringContainsString('name="timezone"', $html, 'brouillon : fuseau modifiable');
            self::assertStringNotContainsString('disabled', $html, 'brouillon : aucun champ verrouillé');
        }
    }

    public function testAnActiveEventLocksTheTypeTheTimezoneAndTheStartInTheForm(): void
    {
        $this->activate($this->fire);
        $this->loginAs($this->w->adminO);

        $html = $this->get($this->editUrl($this->w->org, $this->fire));

        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('Certains champs sont verrouillés', $html);
        self::assertSame(1, substr_count($html, 'name="type"'));
        self::assertMatchesRegularExpression('/<select[^>]*name="type"[^>]*disabled/', $html);
        self::assertMatchesRegularExpression('/<input[^>]*name="start_date"[^>]*disabled/', $html);
        self::assertStringNotContainsString('name="timezone"', $html);
        self::assertDoesNotMatchRegularExpression('/<input[^>]*name="end_date"[^>]*disabled/', $html, 'la fin reste modifiable');
        self::assertDoesNotMatchRegularExpression('/<input[^>]*name="name"[^>]*disabled/', $html);
    }

    // ═══ Modification : succès ═══════════════════════════════════════════════

    public function testEditingADraftPersistsEveryEditableFieldAndShowsItOnTheRecord(): void
    {
        $this->loginAs($this->w->adminO);
        $audits = $this->auditCount('EVENT_UPDATED');

        $this->submit($this->editUrl($this->w->org, $this->fire), [
            'name' => 'Incendie — massif du Nord', 'type' => 'flood', 'description' => 'Nouvelle description', 'instructions' => 'Nouvelles consignes',
            'start_date' => '2026-12-05', 'start_time' => '10:15', 'end_date' => '2026-12-07', 'end_time' => '20:00', 'timezone' => 'America/Martinique',
        ]);

        self::assertSame(303, $this->httpStatus());
        self::assertSame($this->eventUrl($this->w->org, $this->fire), parse_url($this->location(), \PHP_URL_PATH));
        $row = $this->row('Incendie — massif du Nord') ?? self::fail('Modification non enregistrée.');
        self::assertSame('Nouvelle description', $row['description']);
        self::assertSame('Nouvelles consignes', $row['operational_instructions']);
        self::assertSame('America/Martinique', $row['timezone']);
        self::assertSame('2026-12-05 14:15:00', $row['starts_at'], '10:15 à la Martinique (UTC-4)');
        self::assertSame('2026-12-08 00:00:00', $row['ends_at']);
        self::assertSame($audits + 1, $this->auditCount('EVENT_UPDATED'), 'une seule trace d\'audit');

        $page = $this->get($this->eventUrl($this->w->org, $this->fire));
        self::assertStringContainsString('Les modifications ont été enregistrées', $page);
        self::assertStringContainsString('Incendie — massif du Nord', $page);
        self::assertStringContainsString('Nouvelle description', $page);
        self::assertStringContainsString('05/12/2026 à 10:15', $page, 'début affiché dans le nouveau fuseau');
        self::assertStringContainsString('America/Martinique', $page);
        self::assertStringContainsString('Nouvelles consignes', $page, 'consignes opérationnelles visibles de l\'organisatrice');
        self::assertStringContainsString('Incendie — massif du Nord', $this->get($this->listUrl($this->w->org, '?q=massif du Nord')));
    }

    public function testAnUnchangedSubmissionWritesNothing(): void
    {
        $this->loginAs($this->w->adminO);
        $url = $this->editUrl($this->w->org, $this->fire);
        $this->get($url);
        $fields = ['name' => 'Incendie du massif forestier', 'type' => 'fire', 'description' => '', 'instructions' => '', 'start_date' => '2026-12-01', 'start_time' => '09:00', 'end_date' => '', 'end_time' => '', 'timezone' => 'Europe/Paris'];
        $audits = $this->auditCount('EVENT_UPDATED');
        $updatedAt = $this->row('Incendie du massif forestier')['updated_at'] ?? null;

        $this->submit($url, $fields);

        self::assertSame(303, $this->httpStatus());
        self::assertSame($audits, $this->auditCount('EVENT_UPDATED'));
        self::assertSame($updatedAt, $this->row('Incendie du massif forestier')['updated_at'] ?? null);
        self::assertStringContainsString('Aucune modification', $this->get($this->eventUrl($this->w->org, $this->fire)));
    }

    public function testAnActiveEventStillAcceptsTheEditableFields(): void
    {
        $this->activate($this->fire);
        $this->loginAs($this->w->managerO);

        $this->submit($this->editUrl($this->w->org, $this->fire), [
            'name' => 'Incendie actif renommé', 'description' => 'Description mise à jour', 'instructions' => 'Consignes mises à jour',
            'end_date' => '2027-12-24', 'end_time' => '23:00',
        ]);

        self::assertSame(303, $this->httpStatus());
        $row = $this->row('Incendie actif renommé') ?? self::fail();
        self::assertSame('ACTIVE', $row['status']);
        self::assertSame('2026-12-01 08:00:00', $row['starts_at'], 'début inchangé');
        self::assertSame('2027-12-24 22:00:00', $row['ends_at']);
        self::assertSame('fire', $this->em()->getConnection()->fetchOne('SELECT t.code FROM event_type t JOIN event_event e ON e.type_id = t.id WHERE e.name = ?', ['Incendie actif renommé']));
    }

    // ═══ Modification : champs verrouillés, règles du domaine ════════════════

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function lockedAttempts(): iterable
    {
        yield 'type' => [['type' => 'flood'], 'verrouillé'];
        yield 'date de début' => [['start_date' => '2026-12-02', 'start_time' => '09:00'], 'verrouillée'];
        yield 'fuseau horaire' => [['timezone' => 'America/Martinique'], 'verrouillé'];
    }

    /**
     * @param array<string, string> $forged
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('lockedAttempts')]
    public function testALockedFieldForgedInTheRequestIsRefusedByTheServerAndNothingChanges(array $forged, string $expected): void
    {
        $this->activate($this->fire);
        $this->loginAs($this->w->adminO);
        $audits = $this->auditCount('EVENT_UPDATED');
        $before = $this->row('Incendie du massif forestier') ?? self::fail();

        $html = $this->submit($this->editUrl($this->w->org, $this->fire), $forged + ['name' => 'Nom qui ne doit pas être enregistré', 'description' => 'Description conservée à l\'écran']);

        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString($expected, $html);
        self::assertStringContainsString('Nom qui ne doit pas être enregistré', $html, 'saisies conservées dans le formulaire');
        self::assertSame($before, $this->row('Incendie du massif forestier'), 'aucune application partielle');
        self::assertNull($this->row('Nom qui ne doit pas être enregistré'));
        self::assertSame($audits, $this->auditCount('EVENT_UPDATED'));
    }

    public function testAnEndInThePastIsRefusedOnAnActiveEvent(): void
    {
        $this->activate($this->fire);
        $this->loginAs($this->w->adminO);

        $html = $this->submit($this->editUrl($this->w->org, $this->fire), ['name' => 'Incendie du massif forestier', 'end_date' => '2026-12-02', 'end_time' => '10:00']);
        self::assertSame(303, $this->httpStatus(), 'fin future : acceptée');
        $this->em()->getConnection()->executeStatement("UPDATE event_event SET starts_at = '2026-10-01 08:00:00'");

        $html = $this->submit($this->editUrl($this->w->org, $this->fire), ['name' => 'Incendie du massif forestier', 'end_date' => '2026-10-15', 'end_time' => '10:00']);

        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString('déjà passée', $html);
        self::assertStringContainsString('clôturez', $html, 'explication métier du domaine');
    }

    public function testAnInvalidEditKeepsTheInputAndWritesNothing(): void
    {
        $this->loginAs($this->w->adminO);
        $before = $this->row('Incendie du massif forestier');

        $html = $this->submit($this->editUrl($this->w->org, $this->fire), ['name' => '', 'description' => 'À conserver', 'start_date' => '2026-12-01', 'start_time' => '09:00', 'end_date' => '2026-11-01', 'end_time' => '09:00']);
        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString('Indiquez le nom', $html);
        self::assertStringContainsString('À conserver', $html);

        $html = $this->submit($this->editUrl($this->w->org, $this->fire), ['name' => 'Autre nom', 'description' => 'Autre description à conserver', 'start_date' => '2026-12-01', 'start_time' => '09:00', 'end_date' => '2026-11-01', 'end_time' => '09:00']);
        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString('postérieure', $html, 'règle du domaine restituée');
        self::assertStringContainsString('Autre description à conserver', $html);
        self::assertSame($before, $this->row('Incendie du massif forestier'));
    }

    public function testAClosedEventCannotBeEditedByForm(): void
    {
        $this->loginAs($this->w->adminO);

        $this->get($this->editUrl($this->w->org, $this->flood));
        self::assertSame(403, $this->httpStatus());
        $this->client->request('POST', $this->editUrl($this->w->org, $this->flood), ['name' => 'Crue renommée', '_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->row('Crue renommée'));
        self::assertNotNull($this->row('Crue de la Berle'));
    }

    // ═══ Modification : permissions, isolation, forgeries ════════════════════

    public function testEditIsRefusedToThoseWhoMayNotModify(): void
    {
        $cases = [
            'instructeur de l\'organisatrice' => [$this->w->instructorO, $this->w->org, 403],
            'commune participante' => [$this->w->adminB, $this->w->guestB, 403],
            'commune simplement invitée' => [$this->w->adminX, $this->w->other, 403],
        ];
        // L'événement « Accident » a invité `other` ; « Incendie » a invité guestB.
        foreach ($cases as $label => [$user, $municipality, $status]) {
            $this->loginAs($user);
            $event = $municipality === $this->w->other ? $this->accident : $this->fire;
            $this->get($this->editUrl($municipality, $event));
            self::assertSame($status, $this->httpStatus(), $label.' (GET)');
            $this->client->request('POST', $this->editUrl($municipality, $event), ['name' => 'Pirate', '_token' => 'x']);
            self::assertSame($status, $this->httpStatus(), $label.' (POST)');
        }
        self::assertNull($this->row('Pirate'));
    }

    public function testAForeignEventAnAlteredUrlAndForgedIdentifiersAreInaccessible(): void
    {
        $cases = [
            [$this->w->adminX, $this->w->org, $this->fire],            // commune étrangère dans l'URL
            [$this->w->adminX, $this->w->other, $this->fire],          // événement sans lien avec la commune
            [$this->w->adminB, $this->w->guestB, $this->flood],        // événement auquel la commune ne participe pas
            [$this->w->adminO, $this->w->guestB, $this->fire],         // commune dont l'utilisateur n'est pas membre
            [$this->w->adminO, $this->w->org, self::randomUuid()],
            [$this->w->superAdmin, $this->w->org, $this->fire],
        ];
        $bodies = [];
        foreach ($cases as [$user, $municipality, $event]) {
            $this->loginAs($user);
            $bodies[] = $this->get($this->editUrl($municipality, $event));
            self::assertSame(404, $this->httpStatus(), $municipality->name().' / '.$event);
            $this->client->request('POST', $this->editUrl($municipality, $event), ['name' => 'Pirate', '_token' => 'x']);
            self::assertSame(404, $this->httpStatus());
        }
        self::assertSame(1, \count(array_unique($bodies)), 'même réponse pour tous les refus');
        self::assertNull($this->row('Pirate'));

        $this->loginAs($this->w->adminO);
        $this->client->request('GET', $this->listUrl($this->w->org).'/not-a-uuid/modifier');
        self::assertSame(404, $this->httpStatus());
    }

    public function testADeactivatedAccountCannotEdit(): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE identity_user SET active = 0 WHERE id = ?', [$this->w->adminO->id()->toBinary()]);
        $this->loginAs($this->w->adminO);

        $this->client->request('POST', $this->editUrl($this->w->org, $this->fire), ['name' => 'Pirate', '_token' => 'x']);

        self::assertNotSame(303, $this->httpStatus());
        self::assertNull($this->row('Pirate'));
    }

    public function testASuspendedMunicipalityCannotEditItsEvents(): void
    {
        $this->em()->getConnection()->executeStatement("UPDATE municipality SET status = 'SUSPENDED' WHERE id = ?", [$this->w->org->id()->toBinary()]);
        $this->loginAs($this->w->adminO);

        $this->get($this->editUrl($this->w->org, $this->fire));
        self::assertSame(403, $this->httpStatus());
        $this->client->request('POST', $this->editUrl($this->w->org, $this->fire), ['name' => 'Pirate', '_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->row('Pirate'));
    }

    public function testTheEditTokenIsBoundToTheEvent(): void
    {
        $this->loginAs($this->w->adminO);
        $foreignToken = $this->token($this->editUrl($this->w->org, $this->accident));

        $html = $this->submit($this->editUrl($this->w->org, $this->fire), ['name' => 'Jeton d\'un autre événement'], $foreignToken);

        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString('Le formulaire a expiré', $html);
        self::assertNull($this->row('Jeton d\'un autre événement'));
    }

    // ═══ Modification : conflit transactionnel ═══════════════════════════════

    public function testATransactionalConflictOnEditIsReportedAndTheInputIsKept(): void
    {
        $this->loginAs($this->w->adminO);
        $token = $this->token($this->editUrl($this->w->org, $this->fire));
        $other = $this->otherConnection();
        $other->beginTransaction();
        $other->fetchOne('SELECT id FROM event_event WHERE name = ? FOR UPDATE', ['Incendie du massif forestier']); // verrou de l'événement, tenu

        try {
            $html = $this->submit($this->editUrl($this->w->org, $this->fire), ['name' => 'Renommé pendant le conflit', 'description' => 'Description à ne pas perdre'], $token);
        } finally {
            $other->rollBack();
        }

        self::assertSame(409, $this->httpStatus());
        self::assertStringContainsString('Un autre traitement est en cours', $html);
        self::assertStringContainsString('Renommé pendant le conflit', $html, 'saisies conservées');
        self::assertStringContainsString('Description à ne pas perdre', $html);
        self::assertStringContainsString('name="_token"', $html);
        self::assertNull($this->row('Renommé pendant le conflit'), 'aucun succès annoncé');
        self::assertNotNull($this->row('Incendie du massif forestier'));

        $this->submit($this->editUrl($this->w->org, $this->fire), ['name' => 'Renommé pendant le conflit', 'description' => 'Description à ne pas perdre']);
        self::assertSame(303, $this->httpStatus(), 'nouvelle tentative manuelle');
        self::assertNotNull($this->row('Renommé pendant le conflit'));
    }

    public function testOperationalInstructionsStayWithTheOrganizingMunicipality(): void
    {
        $this->loginAs($this->w->adminO);
        $this->submit($this->editUrl($this->w->org, $this->fire), ['name' => 'Incendie du massif forestier', 'instructions' => 'Consigne réservée à l\'organisatrice', 'start_date' => '2026-12-01', 'start_time' => '09:00']);
        self::assertSame(303, $this->httpStatus());

        $this->loginAs($this->w->instructorO);
        self::assertStringContainsString('Consigne réservée', $this->get($this->eventUrl($this->w->org, $this->fire)), 'agents de la commune organisatrice');
        $this->loginAs($this->w->adminB);
        self::assertStringNotContainsString('Consigne réservée', $this->get($this->eventUrl($this->w->guestB, $this->fire)), 'commune participante : pas de consignes');
    }
}
