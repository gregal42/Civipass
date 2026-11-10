<?php

declare(strict_types=1);

namespace App\Tests\Functional\Permit;

use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneValidity;
use App\Event\Domain\Validity\ValiditySlot;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Permit\Application\PermitAccessService;
use App\Permit\Domain\PermitRepository;
use App\Permit\UI\Http\PublicVerificationHeadersListener;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\MunicipalInstructionService;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\TravelMode;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\PublicRequestTestCase;
use App\Tests\Support\TimeFactory as T;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Vérification publique `/v/{jeton}` dans le navigateur simulé (LOT 05.4.2, DM-115) : page complète, sept statuts, compteur N/M, zones retirées
 * à part, périodes multiples, QR remplacé (410), 404 uniforme, interrupteur d'exploitation, en-têtes et CSP sur TOUTES les réponses, aucun
 * cookie, aucune redirection, aucune donnée interdite, aucun jeton dans la page ni dans les journaux (y compris sur une panne SQL).
 * L'interrupteur est activé ICI, explicitement, dans un environnement de test isolé : il est désactivé partout ailleurs.
 */
#[Group('requires-request-schema')]
final class PublicVerificationPageTest extends PublicRequestTestCase
{
    private const ADDRESS = '12 rue SENTINELLE des Lilas';
    private const EMAIL = 'sentinelle.secrete@exemple.test';
    private const PHONE = '0611223344';
    private const MOTIF_DETAIL = 'SENTINELLE-MOTIF-DETAIL';

    private User $instructor;
    private string $organizerId;
    private string $partnerId;
    private string $requestId;
    private ?string $previousSwitch = null;
    private string $previousVerbosity = '';
    private string $previousErrorLog = '';

    protected function setUp(): void
    {
        $this->previousSwitch = $_SERVER['PUBLIC_VERIFICATION_ENABLED'] ?? null;
        self::switchTo('1');
        $this->previousVerbosity = (string) ($_SERVER['SHELL_VERBOSITY'] ?? '');
        $this->previousErrorLog = (string) ini_get('error_log');
        parent::setUp();
        $organizer = $this->em()->find(Municipality::class, $this->organizerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $partner = $this->em()->find(Municipality::class, $this->partnerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $this->organizerId = $organizer->id()->toRfc4122();
        $this->partnerId = $partner->id()->toRfc4122();
        $this->instructor = UserBuilder::aUser()->build();
        $this->save($this->instructor, MembershipBuilder::aMembership($this->instructor, $organizer)->withRoles(Role::Instructor)->build());
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->sql('UPDATE event_event SET starts_at = :s, ends_at = :e', ['s' => $now->modify('-1 day')->format('Y-m-d H:i:s'), 'e' => $now->modify('+10 days')->format('Y-m-d H:i:s')]);
        $this->requestId = static::getContainer()->get(RequestSubmissionService::class)->submitPublic(
            new SubmitRequestCommand($this->event->id()->toRfc4122(), 'Martin', 'Camille', self::ADDRESS, self::EMAIL, self::PHONE, 'private_person', 'other', self::MOTIF_DETAIL, TravelMode::Vehicle, ['GH-482-KT', 'AB-123-CD'], [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()], true),
            sprintf('verif-test-%016d', random_int(1, 999999)),
        )->requestId;
        $instruction = static::getContainer()->get(MunicipalInstructionService::class);
        $instruction->approve($this->managed($this->instructor), $this->organizerId, $this->requestId, ApprovalDuration::untilClosure());
        $partnerEntity = $this->em()->find(Municipality::class, $this->partnerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $partnerAgent = UserBuilder::aUser()->build();
        $this->save($partnerAgent, MembershipBuilder::aMembership($partnerAgent, $partnerEntity)->withRoles(Role::Instructor)->build());
        $instruction->approve($this->managed($partnerAgent), $this->partnerId, $this->requestId, ApprovalDuration::untilClosure());
    }

    protected function tearDown(): void
    {
        self::switchTo($this->previousSwitch ?? '0');
        if ('' === $this->previousVerbosity) {
            unset($_SERVER['SHELL_VERBOSITY'], $_ENV['SHELL_VERBOSITY']);
        } else {
            $_SERVER['SHELL_VERBOSITY'] = $_ENV['SHELL_VERBOSITY'] = $this->previousVerbosity;
        }
        ini_set('error_log', $this->previousErrorLog);
        parent::tearDown();
    }

    private static function switchTo(string $value): void
    {
        $_SERVER['PUBLIC_VERIFICATION_ENABLED'] = $_ENV['PUBLIC_VERIFICATION_ENABLED'] = $value;
        putenv('PUBLIC_VERIFICATION_ENABLED='.$value);
    }

    private function managed(User $user): User
    {
        return $this->em()->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.');
    }

    private function token(): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT token FROM permit_verification_token');
    }

    /** Noyau neuf, sans redémarrage entre requêtes (pour remplacer un service avant son premier usage) ; base de test conservée. */
    private function freshKernel(bool $debug = true): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient(['debug' => $debug]);
        $this->client->disableReboot();
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    private function open(?string $token = null, string $suffix = ''): string
    {
        $this->client->request('GET', '/v/'.($token ?? $this->token()).$suffix);

        return $this->html();
    }

    private function assertProtected(string $context): void
    {
        $response = $this->client->getResponse();
        $h = $response->headers;
        self::assertSame('no-store, private', $h->get('Cache-Control'), $context);
        self::assertSame('no-cache', $h->get('Pragma'), $context);
        self::assertSame('no-referrer', $h->get('Referrer-Policy'), $context);
        self::assertSame('noindex, nofollow, noarchive', $h->get('X-Robots-Tag'), $context);
        self::assertSame('nosniff', $h->get('X-Content-Type-Options'), $context);
        self::assertSame('DENY', $h->get('X-Frame-Options'), $context);
        self::assertSame(PublicVerificationHeadersListener::CSP, $h->get('Content-Security-Policy'), $context);
        self::assertSame([], $response->headers->getCookies(), $context.' : aucun cookie');
        self::assertFalse($h->has('Set-Cookie'), $context);
        self::assertFalse($h->has('Location'), $context.' : aucune redirection');
    }

    // ------------------------------------------------------------------ page complète

    public function testTheFoundPageShowsEverythingTheControllerNeeds(): void
    {
        $html = $this->open();

        self::assertResponseStatusCodeSame(200);
        foreach ([
            'Droits actifs', '2 zones actives sur 2', 'cp-verdict--valid',
            'Camille MARTIN', 'LP-2026-0001', $this->event->name(),
            'GH-482-KT', 'AB-123-CD',
            $this->organizerParticipation->municipality()->name(), $this->partnerParticipation->municipality()->name(),
            $this->organizerZone->label(), 'Valide actuellement',
            'Vérifié le', 'Droits valables au moment de cette vérification', 'Dernière vérification', 'Actualiser',
        ] as $expected) {
            self::assertStringContainsString($expected, $html, $expected);
        }
        $this->assertProtected('page trouvée');
        self::assertStringContainsString('<title>Vérification · Civipass</title>', $html, 'le titre ne porte jamais le nom du bénéficiaire');
    }

    public function testTheRefreshButtonIsAPlainGetNavigationWithoutAutomaticRefresh(): void
    {
        $token = $this->token();
        $html = $this->open($token);

        self::assertMatchesRegularExpression('#<a class="cp-btn cp-btn--primary cp-btn--l vp-refresh__button" href="" rel="nofollow">#', $html);
        self::assertStringNotContainsString('http-equiv="refresh"', strtolower($html));
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString($token, $html, 'le jeton n\'est écrit nulle part dans la page');
        self::assertStringNotContainsString(strtoupper($token), $html);
    }

    public function testThePageIsMobileFirstSingleColumnWithoutTables(): void
    {
        $html = $this->open();

        self::assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">', $html);
        self::assertStringContainsString('<main id="main" class="vp-main">', $html);
        self::assertStringNotContainsString('<table', $html);
        $css = (string) file_get_contents(\dirname(__DIR__, 3).'/assets/styles/civipass-app.css');
        $block = substr($css, (int) strpos($css, 'Vérification publique d\'un laissez-passer (LOT 05.4.2)'));
        self::assertDoesNotMatchRegularExpression('/\bwidth:\s*\d{3,}px/', $block, 'aucune largeur fixe supérieure à un écran de 390 px');
        self::assertStringContainsString('.vp-main', $block);
        self::assertStringContainsString('min-height: 52px', $block, 'bouton Actualiser large et facile à toucher');
    }

    // ------------------------------------------------------------------ sept statuts

    /**
     * @return iterable<string, array{list<string>, string, string, string, string}>
     */
    public static function verdicts(): iterable
    {
        yield 'droits actifs, toutes les zones' => [[], 'Droits actifs', '2 zones actives sur 2', 'cp-verdict--valid', 'Valide actuellement'];
        yield 'droits actifs, une partie' => [["UPDATE event_zone SET status = 'SUSPENDED' WHERE id = :partnerZone"], 'Droits actifs', '1 zone active sur 2', 'cp-verdict--uncertain', 'Zone suspendue'];
        yield 'à venir' => [['UPDATE permit_authorization SET valid_from = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 2 DAY)'], 'Aucun droit actif · droits à venir', '0 zones actives sur 2', 'cp-verdict--impossible', 'Pas encore valide'];
        yield 'suspendus' => [["UPDATE event_zone SET status = 'SUSPENDED'"], 'Droits suspendus', '0 zones actives sur 2', 'cp-verdict--uncertain', 'Zone suspendue'];
        // Événement terminé : page neutre sans donnée (décision du 10 novembre 2026), voir `PublicVerificationClosedEventTest`.
        yield 'droits révoqués' => [['UPDATE permit_authorization SET revoked_at = UTC_TIMESTAMP()'], 'Droits révoqués', '0 zones actives sur 2', 'cp-verdict--invalid', 'Révoquée'];
        yield 'droits expirés' => [["UPDATE permit_authorization SET duration_mode = 'CUSTOM', valid_from = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY), valid_until = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)"], 'Droits expirés', '0 zones actives sur 2', 'cp-verdict--impossible', 'Expirée'];
        yield 'aucun droit actif (états définitifs mélangés)' => [["UPDATE permit_authorization SET duration_mode = 'CUSTOM', valid_from = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY), valid_until = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)", 'UPDATE permit_authorization SET revoked_at = UTC_TIMESTAMP() WHERE municipality_id = :partner'], 'Aucun droit actif', '0 zones actives sur 2', 'cp-verdict--invalid', 'Révoquée'];
    }

    /**
     * @param list<string> $updates
     */
    #[DataProvider('verdicts')]
    public function testTheSevenGlobalStatusesAreWrittenWithTheirCounter(array $updates, string $title, string $count, string $tone, string $zone): void
    {
        foreach ($updates as $update) {
            $this->sql($update, array_filter([
                'partnerZone' => $this->partnerZone->id()->toBinary(),
                'partner' => $this->partnerParticipation->municipality()->id()->toBinary(),
            ], static fn (string $_, string $key): bool => str_contains($update, ':'.$key), \ARRAY_FILTER_USE_BOTH));
        }

        $html = $this->open();

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('>'.$title.'<', $html);
        self::assertStringContainsString($count, $html, 'le compteur N sur M est toujours écrit');
        self::assertStringContainsString($tone, $html);
        self::assertStringContainsString($zone, $html, 'le détail de chaque zone reste visible');
        self::assertMatchesRegularExpression('#<span class="cp-verdict__badge"><svg#', $html, 'une icône accompagne le statut');
    }

    // ------------------------------------------------------------------ zones retirées et périodes

    public function testARemovedZoneIsShownApartAndOutsideTheCounter(): void
    {
        $second = F::readyZone($this->em()->find(EventParticipation::class, $this->organizerParticipation->id()) ?? self::fail('Participation absente.'));
        $this->save($second->createdBy(), $second);
        // Une seconde zone de la même commune est autorisée par une approbation de la même autorisation : on la copie en SQL, puis on la retire.
        $this->sql('INSERT INTO permit_authorization_zone (id, authorization_id, zone_id, zone_label, removed_at) SELECT UNHEX(REPLACE(UUID(), \'-\', \'\')), a.id, :z, :l, :t FROM permit_authorization a', ['z' => $second->id()->toBinary(), 'l' => 'Zone retirée de test', 't' => '2026-06-12 09:45:00']);

        $html = $this->open();

        self::assertStringContainsString('Zones retirées', $html);
        self::assertStringContainsString('Zone retirée de test', $html);
        self::assertStringContainsString('Retirée le 12/06/2026 à 11:45', $html);
        self::assertStringContainsString('Aucun droit.', $html);
        self::assertStringContainsString('2 zones actives sur 2', $html, 'compteur hors zones retirées');
        self::assertStringContainsString('cp-verdict--valid', $html, 'une zone retirée ne rend pas le bandeau ambre');
        self::assertLessThan(strpos($html, 'Zones retirées'), strpos($html, 'Valide actuellement'), 'les zones retirées viennent après les zones actuelles');
    }

    public function testSeveralPeriodsOfAZoneAreInANativeDetailsElement(): void
    {
        $zone = $this->em()->find(EventZone::class, $this->organizerZone->id()) ?? self::fail();
        $this->sql('UPDATE event_zone SET validity_mode = \'SPECIFIC_PERIODS\' WHERE id = :id', ['id' => $zone->id()->toBinary()]);
        $day1 = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d');
        $day2 = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+3 days')->format('Y-m-d');
        $zone = $this->em()->find(EventZone::class, $this->organizerZone->id()) ?? self::fail();
        $this->save(
            EventZoneValidity::fromSlot($zone, ValiditySlot::punctual(T::date($day1), T::time('09:00'), T::time('17:00')), F::now()),
            EventZoneValidity::fromSlot($zone, ValiditySlot::punctual(T::date($day2), T::time('09:00'), T::time('17:00')), F::now()),
        );

        $html = $this->open();

        self::assertStringContainsString('<details class="vp-periods">', $html);
        self::assertStringContainsString('Périodes (2)', $html);
        self::assertSame(3, substr_count($html, 'vp-period__phase'), 'une ligne par période : deux créneaux de la zone organisatrice, une période de la zone partenaire');
        self::assertStringContainsString('Périodes (1)', $html);
        self::assertStringContainsString('À venir', $html);
        self::assertStringContainsString('Pas encore valide', $html, 'l\'état de la zone reste écrit à côté de ses périodes');
        self::assertStringContainsString('1 zone active sur 2', $html);
        self::assertStringContainsString('cp-verdict--uncertain', $html, 'une zone sur deux seulement : ambre, jamais vert');
        self::assertStringNotContainsString('cp-verdict--valid', $html);
    }

    // ------------------------------------------------------------------ QR remplacé, inconnu, malformé, interrupteur

    public function testAReplacedQrGivesA410WithoutAnyPersonalData(): void
    {
        $token = $this->token();
        $this->sql('UPDATE permit_verification_token SET retired_at = UTC_TIMESTAMP(), active_permit_id = NULL');

        $html = $this->open($token);

        self::assertResponseStatusCodeSame(410);
        self::assertStringContainsString('Ce QR code a été remplacé', $html);
        foreach (['Martin', 'Camille', 'LP-2026', $this->event->name(), 'GH-482-KT', 'AB-123-CD', $this->organizerZone->label(), $this->organizerParticipation->municipality()->name(), $token] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html, 'aucune donnée pour un QR remplacé');
        }
        $this->assertProtected('QR remplacé');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableTokens(): iterable
    {
        yield 'bien formé mais inconnu' => ['zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz'];
        yield 'trop court' => ['abc'];
        yield 'trop long' => [str_repeat('a', 150)];
        yield 'hors alphabet' => ['0000000000000000000000000000000000'];
        yield 'caractères spéciaux' => ['%27%20OR%201%3D1%20--'];
        yield 'point et extension' => ['abcdefghijklmnopqrstuvwxyz234567.pdf'];
    }

    #[DataProvider('unreadableTokens')]
    public function testAnUnknownOrMalformedTokenGetsExactlyTheSameAnswerAsAnyOther(string $token): void
    {
        $this->client->request('GET', '/v/zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz2');
        $reference = $this->client->getResponse();
        $referenceBody = (string) $reference->getContent();
        $referenceHeaders = $this->comparableHeaders();

        $this->client->request('GET', '/v/'.$token);

        self::assertResponseStatusCodeSame(404);
        self::assertSame($referenceBody, $this->html(), 'même corps, octet pour octet');
        self::assertSame($referenceHeaders, $this->comparableHeaders(), 'mêmes en-têtes');
        self::assertStringNotContainsString('Martin', $this->html());
        $this->assertProtected('404 uniforme');
    }

    /**
     * @return array<string, string>
     */
    private function comparableHeaders(): array
    {
        $headers = $this->client->getResponse()->headers->all();
        unset($headers['date']);
        ksort($headers);

        return array_map(static fn (array $v): string => implode('|', $v), $headers);
    }

    public function testADisabledSwitchAnswersTheSame404WithoutAnyBusinessQuery(): void
    {
        $token = $this->token();
        $this->client->request('GET', '/v/zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz2');
        $unknownBody = $this->html();
        $unknownHeaders = $this->comparableHeaders();
        self::switchTo('0');
        $this->freshKernel();
        static::getContainer()->set(PermitRepository::class, new TripwirePermitRepository());
        $questions = fn (): int => (int) ($this->em()->getConnection()->fetchNumeric("SHOW SESSION STATUS LIKE 'Questions'")[1] ?? 0);
        $before = $questions();

        $this->client->request('GET', '/v/'.$token);

        self::assertResponseStatusCodeSame(404);
        self::assertSame($unknownBody, $this->html(), 'indiscernable d\'un jeton inconnu');
        self::assertSame($unknownHeaders, $this->comparableHeaders());
        self::assertStringNotContainsString('Martin', $this->html());
        self::assertSame(0, $questions() - $before - 1, 'aucune requête métier (ni lecture du jeton) quand l\'interrupteur est coupé');
        $this->assertProtected('interrupteur coupé');
    }

    public function testTheSwitchIsOffByDefaultInTheConfiguration(): void
    {
        $env = (string) file_get_contents(\dirname(__DIR__, 3).'/.env');
        $services = (string) file_get_contents(\dirname(__DIR__, 3).'/config/services.yaml');

        self::assertMatchesRegularExpression('/^PUBLIC_VERIFICATION_ENABLED=0$/m', $env);
        self::assertStringContainsString("env(PUBLIC_VERIFICATION_ENABLED): '0'", $services, 'variable absente = désactivé');
    }

    // ------------------------------------------------------------------ en-têtes, cookies, redirections

    public function testEveryResponseOfThePathCarriesTheProtectionHeaders(): void
    {
        $token = $this->token();
        $this->freshKernel(false); // noyau de production : les pages d'erreur de débogage citent l'URL (développement seulement)
        $cases = [
            'trouvé' => ['GET', '/v/'.$token],
            'inconnu' => ['GET', '/v/zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz2'],
            'bare /v/' => ['GET', '/v/'],
            'segment en trop' => ['GET', '/v/'.$token.'/pdf'],
            'méthode interdite' => ['POST', '/v/'.$token],
            'HEAD' => ['HEAD', '/v/'.$token],
        ];
        foreach ($cases as $name => [$method, $uri]) {
            $this->client->request($method, $uri);
            $this->assertProtected($name);
            self::assertStringNotContainsString($token, $this->html(), $name.' : jamais le jeton dans la réponse');
        }
        $this->sql('UPDATE permit_verification_token SET retired_at = UTC_TIMESTAMP(), active_permit_id = NULL');
        $this->client->request('GET', '/v/'.$token);
        $this->assertProtected('remplacé');
    }

    public function testAnErrorResponseOfThePathIsProtectedAndGeneric(): void
    {
        $token = $this->token();
        $this->freshKernel(false);
        static::getContainer()->set(PermitRepository::class, new TripwirePermitRepository(new \RuntimeException('panne')));

        $this->client->request('GET', '/v/'.$token);

        self::assertResponseStatusCodeSame(500);
        $this->assertProtected('erreur 500');
        self::assertStringNotContainsString($token, $this->html());
        self::assertStringNotContainsString('panne', $this->html(), 'aucun détail technique en production');
        self::assertStringNotContainsString('Stack trace', $this->html());
    }

    public function testNoCookieEvenWhenTheRequestCarriesSessionOrRememberMeCookies(): void
    {
        $token = $this->token();
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('MOCKSESSID', 'forged-session'));
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('REMEMBERME', 'forged-remember-me'));

        $this->client->request('GET', '/v/'.$token);

        self::assertResponseStatusCodeSame(200);
        $this->assertProtected('avec cookies forgés');
    }

    public function testNoCookieAndNoAccountInformationForALoggedInAgent(): void
    {
        $token = $this->token();
        $this->client->loginUser($this->managed($this->instructor));

        $this->client->request('GET', '/v/'.$token);

        self::assertResponseStatusCodeSame(200);
        $this->assertProtected('agent connecté');
        self::assertStringNotContainsString('Se déconnecter', $this->html(), 'aucune information de compte sur la page publique');
    }

    public function testNoRedirectEverCarriesTheToken(): void
    {
        $token = $this->token();
        foreach (['/v/'.$token.'/', '/v/'.$token.'?x=1', '/V/'.$token, '/v/'.strtoupper($token), '//v/'.$token, '/v/'.$token.'%2F', '/index.php/v/'.$token] as $uri) {
            $this->client->request('GET', $uri);
            $status = $this->client->getResponse()->getStatusCode();
            self::assertFalse($this->client->getResponse()->isRedirection(), $uri.' : '.$status);
            self::assertFalse($this->client->getResponse()->headers->has('Location'), $uri);
        }
    }

    public function testOnlyTheOnePublicControlRouteExistsAndItIsReadOnly(): void
    {
        $routes = static::getContainer()->get('router')->getRouteCollection();
        $verification = [];
        foreach ($routes as $name => $route) {
            if (str_starts_with($route->getPath(), '/v/') || '/v' === $route->getPath()) {
                $verification[$name] = [$route->getPath(), $route->getMethods()];
            }
        }

        self::assertSame(['app_public_verification' => ['/v/{token}', ['GET']], 'app_public_verification_slash' => ['/v/{token}/', ['GET']]], $verification, 'deux chemins pour une seule page : aucune redirection de slash final');
    }

    // ------------------------------------------------------------------ CSP et données interdites

    public function testThePageIsCompatibleWithTheStrictCspAndLoadsNothingFromElsewhere(): void
    {
        $html = $this->open();

        self::assertStringNotContainsString('<script', $html);
        self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html, 'la CSP interdit les styles en ligne');
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html);
        self::assertDoesNotMatchRegularExpression('#(?:href|src|action)\s*=\s*"(?:https?:)?//#i', $html, 'aucune ressource ni lien externe');
        self::assertDoesNotMatchRegularExpression('#<(?:iframe|object|embed|form)\b#i', $html);
        preg_match_all('#<link[^>]+href="([^"]+)"#', $html, $links);
        foreach ($links[1] as $href) {
            self::assertStringStartsWith('/', $href, 'feuilles de style locales seulement');
        }
        self::assertStringContainsString("default-src 'none'", (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testNoForbiddenDataReachesThePage(): void
    {
        $secret = static::getContainer()->get(PermitAccessService::class)->issue($this->managed($this->instructor), $this->organizerId, $this->requestId)->secret;
        $token = $this->token();
        $permitId = (string) $this->em()->getConnection()->fetchOne('SELECT LOWER(CONCAT_WS(\'-\', HEX(SUBSTR(id,1,4)), HEX(SUBSTR(id,5,2)), HEX(SUBSTR(id,7,2)), HEX(SUBSTR(id,9,2)), HEX(SUBSTR(id,11,6)))) FROM permit_permit');
        $html = $this->open($token);

        foreach ([self::ADDRESS, self::EMAIL, self::PHONE, self::MOTIF_DETAIL, 'SENTINELLE', 'private_person', 'Particulier', $secret, $token, $permitId, $this->event->id()->toRfc4122(), $this->organizerZone->id()->toRfc4122(), '/laissez-passer/'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html, 'donnée interdite dans la page : '.$forbidden);
        }
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $html, 'aucun identifiant technique');
        self::assertStringNotContainsString('Véhicule', str_replace('Aucun véhicule associé', '', $html), 'mode de déplacement non affiché');
    }

    // ------------------------------------------------------------------ exceptions SQL et journaux

    /**
     * Preuve de non-divulgation dans les journaux APPLICATIFS : journalisation poussée au niveau debug (le plus bavard), noyau de production,
     * requêtes réelles couvrant chaque issue ET une vraie panne SQL (table du jeton indisponible, requête réellement rejetée par MySQL). Le
     * journal capturé doit être non vide (le test ne prouve rien sinon) et ne contenir aucun jeton ni donnée sentinelle.
     */
    public function testTheTokenNeverReachesTheApplicationLogsEvenOnARealSqlFailure(): void
    {
        $token = $this->token();
        $logFile = tempnam(sys_get_temp_dir(), 'civipass-log');
        self::assertIsString($logFile);
        ini_set('error_log', $logFile);
        $_SERVER['SHELL_VERBOSITY'] = $_ENV['SHELL_VERBOSITY'] = '3';
        $this->freshKernel(false);

        try {
            foreach (['/v/'.$token, '/v/'.strtoupper($token), '/v/zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz2', '/v/abc', '/v/'.$token.'/extra', '/v/'.$token.'.pdf', '/v/'.$token.'/'] as $uri) {
                $this->client->request('GET', $uri);
            }
            $this->client->request('POST', '/v/'.$token);
            $this->sql('UPDATE permit_verification_token SET retired_at = UTC_TIMESTAMP(), active_permit_id = NULL');
            $this->client->request('GET', '/v/'.$token);
            $this->sql('UPDATE permit_verification_token SET retired_at = NULL, active_permit_id = (SELECT id FROM permit_permit)');

            // Vraie panne SQL : la table des jetons n'existe plus le temps de la requête.
            $connection = $this->em()->getConnection();
            $connection->executeStatement('RENAME TABLE permit_verification_token TO permit_verification_token_off');
            try {
                $this->client->request('GET', '/v/'.$token);
                $failureStatus = $this->client->getResponse()->getStatusCode();
                $failureBody = $this->html();
            } finally {
                $connection->executeStatement('RENAME TABLE permit_verification_token_off TO permit_verification_token');
            }
        } finally {
            ini_set('error_log', $this->previousErrorLog);
        }

        $logs = (string) file_get_contents($logFile);
        unlink($logFile);
        self::assertSame(500, $failureStatus);
        self::assertStringNotContainsString($token, $failureBody);
        self::assertStringContainsString('Uncaught PHP Exception', $logs, 'la panne SQL est bien journalisée (le test n\'est pas vide)');
        self::assertStringContainsString('NotFoundHttpException', $logs, 'les 404 du routeur sont journalisées');
        self::assertStringContainsString('[info] Matched route', $logs, 'journalisation poussée au niveau info (les messages du routeur sont écrits)');
        foreach ([$token, strtoupper($token), self::ADDRESS, self::EMAIL, self::PHONE, self::MOTIF_DETAIL] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $logs, 'secret ou donnée personnelle dans les journaux applicatifs');
        }
        self::assertStringContainsString('[masqué]', $logs, 'les chemins cités par le routeur sont masqués, pas supprimés');
        self::assertStringContainsString('SQLSTATE', $logs, 'le diagnostic de la panne SQL est conservé');
    }

    public function testTheSqlExceptionMessageAndTraceNeverCarryTheToken(): void
    {
        $token = $this->token();
        $connection = $this->em()->getConnection();
        $connection->executeStatement('RENAME TABLE permit_verification_token TO permit_verification_token_off');
        $caught = null;
        try {
            static::getContainer()->get(\App\Permit\Application\PublicVerificationReader::class)->find($token, new \DateTimeImmutable());
        } catch (\Throwable $e) {
            $caught = $e;
        } finally {
            $connection->executeStatement('RENAME TABLE permit_verification_token_off TO permit_verification_token');
        }

        self::assertNotNull($caught, 'la panne SQL remonte bien');
        for ($e = $caught; null !== $e; $e = $e->getPrevious()) {
            self::assertStringNotContainsString($token, $e->getMessage(), 'message de '.$e::class);
            self::assertStringNotContainsString($token, $e->getTraceAsString(), 'trace de '.$e::class.' (arguments masqués par #[\\SensitiveParameter])');
        }
    }
}
