<?php

declare(strict_types=1);

namespace App\Tests\Functional\Permit;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Permit\UI\Http\PublicVerificationHeadersListener;
use App\Permit\UI\Http\ThrottleStorageUnavailable;
use App\Permit\UI\Http\VerificationThrottle;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\MunicipalInstructionService;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\TravelMode;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\PublicRequestTestCase;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Sécurité renforcée du contrôle public (LOT 05.4.3) : pare-feu sans session, deux limiteurs indépendants, IP partagée, IPv6 et IPv4-mappée, en-têtes
 * de provenance forgés, réponses 429 et 503, panne du stockage visible, aucun jeton dans les journaux. Les plafonds sont réduits par variables
 * d'environnement (VERIFICATION_ACCESS_LIMIT / VERIFICATION_FAILURE_LIMIT) pour que les tests restent rapides ; l'interrupteur est activé ICI seulement.
 */
#[Group('requires-request-schema')]
final class PublicVerificationSecurityTest extends PublicRequestTestCase
{
    private const UNKNOWN = 'zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz2';
    private const VARS = ['PUBLIC_VERIFICATION_ENABLED', 'VERIFICATION_ACCESS_LIMIT', 'VERIFICATION_FAILURE_LIMIT', 'SHELL_VERBOSITY'];

    private User $instructor;
    /** @var array<string, string|null> */
    private array $previous = [];
    private string $previousErrorLog = '';

    protected function setUp(): void
    {
        foreach (self::VARS as $name) {
            $this->previous[$name] = $_SERVER[$name] ?? null;
        }
        $this->previousErrorLog = (string) ini_get('error_log');
        $this->env('PUBLIC_VERIFICATION_ENABLED', '1');
        $this->env('VERIFICATION_ACCESS_LIMIT', '1000');
        $this->env('VERIFICATION_FAILURE_LIMIT', '1000');
        parent::setUp();
        $organizer = $this->em()->find(Municipality::class, $this->organizerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $this->instructor = UserBuilder::aUser()->build();
        $this->save($this->instructor, MembershipBuilder::aMembership($this->instructor, $organizer)->withRoles(Role::Instructor)->build());
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->sql('UPDATE event_event SET starts_at = :s, ends_at = :e', ['s' => $now->modify('-1 day')->format('Y-m-d H:i:s'), 'e' => $now->modify('+10 days')->format('Y-m-d H:i:s')]);
        $requestId = static::getContainer()->get(RequestSubmissionService::class)->submitPublic(
            new SubmitRequestCommand($this->event->id()->toRfc4122(), 'Martin', 'Camille', '12 rue des Lilas', 'camille@exemple.fr', '0611223344', 'private_person', 'home', null, TravelMode::Vehicle, ['GH-482-KT'], [$this->organizerZone->id()->toRfc4122()], true),
            sprintf('sec-test-%016d', random_int(1, 999999)),
        )->requestId;
        static::getContainer()->get(MunicipalInstructionService::class)->approve($this->em()->find(User::class, $this->instructor->id()) ?? self::fail(), $organizer->id()->toRfc4122(), $requestId, ApprovalDuration::untilClosure());
    }

    protected function tearDown(): void
    {
        foreach (self::VARS as $name) {
            if (null === $this->previous[$name]) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);
            } else {
                $this->env($name, $this->previous[$name]);
            }
        }
        ini_set('error_log', $this->previousErrorLog);
        parent::tearDown();
    }

    private function env(string $name, string $value): void
    {
        $_SERVER[$name] = $_ENV[$name] = $value;
        putenv($name.'='.$value);
    }

    private function token(): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT token FROM permit_verification_token');
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * @param array<string, string> $server
     */
    private function get(string $uri, string $address = '198.51.100.10', array $server = []): int
    {
        $this->client->request('GET', $uri, server: ['REMOTE_ADDR' => $address] + $server);

        return $this->client->getResponse()->getStatusCode();
    }

    private function retired(): void
    {
        $this->sql('UPDATE permit_verification_token SET retired_at = UTC_TIMESTAMP(), active_permit_id = NULL');
    }

    private function freshKernel(bool $debug = true): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient(['debug' => $debug]);
        $this->client->disableReboot();
    }

    private function assertProtected(string $context): void
    {
        $h = $this->client->getResponse()->headers;
        self::assertSame('no-store, private', $h->get('Cache-Control'), $context);
        self::assertSame(PublicVerificationHeadersListener::CSP, $h->get('Content-Security-Policy'), $context);
        self::assertSame('noindex, nofollow, noarchive', $h->get('X-Robots-Tag'), $context);
        self::assertSame([], $this->client->getResponse()->headers->getCookies(), $context.' : aucun cookie');
        self::assertFalse($h->has('Set-Cookie'), $context);
        self::assertFalse($h->has('Location'), $context);
    }

    // ------------------------------------------------------------------ pare-feu et session

    public function testNoSessionIsEverInstantiatedOnThePublicPathEvenForAnAuthenticatedAgent(): void
    {
        $token = $this->token();
        $this->client->loginUser($this->em()->find(User::class, $this->instructor->id()) ?? self::fail());

        $this->client->request('GET', '/v/'.$token);

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->client->getRequest()->hasSession(true), 'aucune session instanciée, même avec le cookie de session d\'un agent connecté');
        $this->assertProtected('agent connecté');
        self::assertStringNotContainsString('Se déconnecter', $this->html());
    }

    public function testNoSessionWithForgedSessionAndRememberMeCookies(): void
    {
        $this->client->getCookieJar()->set(new Cookie('MOCKSESSID', 'forged-session'));
        $this->client->getCookieJar()->set(new Cookie('REMEMBERME', 'forged-remember-me'));

        foreach (['/v/'.$this->token(), '/v/'.self::UNKNOWN, '/v/abc', '/v/'.$this->token().'/extra'] as $uri) {
            $this->client->request('GET', $uri);
            self::assertFalse($this->client->getRequest()->hasSession(true), $uri);
            $this->assertProtected($uri);
        }
    }

    public function testThePositiveControlShowsThatASessionDoesStartOnAMainFirewallPage(): void
    {
        $this->client->request('GET', '/login');

        self::assertTrue($this->client->getRequest()->hasSession(true), 'témoin : la même vérification détecte bien une session sur une page du pare-feu principal');
    }

    public function testNeighbouringRoutesAndErrorsKeepTheirBehaviour(): void
    {
        $this->client->request('GET', '/v');
        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->client->getRequest()->hasSession(true));
        $this->assertProtected('/v');

        $this->client->request('POST', '/v/'.$this->token());
        self::assertResponseStatusCodeSame(405);
        self::assertFalse($this->client->getRequest()->hasSession(true));
        $this->assertProtected('405');

        $this->client->request('GET', '/versions');
        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->client->getResponse()->headers->has('Content-Security-Policy'), 'une route voisine n\'est pas concernée par le listener public');

        $this->client->request('GET', '/communes');
        self::assertResponseRedirects('/login', null, 'le pare-feu principal protège toujours l\'espace municipal');
        self::assertTrue($this->client->getRequest()->hasSession(true));
    }

    public function testTheFirewallIsDeclaredBeforeTheMainOne(): void
    {
        $yaml = (string) file_get_contents(\dirname(__DIR__, 3).'/config/packages/security.yaml');

        $dev = strpos($yaml, "        dev:\n");
        $public = strpos($yaml, "        public_verification:\n");
        $main = strpos($yaml, "        main:\n");
        self::assertNotFalse($dev);
        self::assertNotFalse($public);
        self::assertNotFalse($main);
        self::assertLessThan($public, $dev);
        self::assertLessThan($main, $public, 'la première correspondance l\'emporte : public_verification avant main');
        self::assertStringContainsString("pattern: ^/v(?:/|\$)\n            security: false", $yaml);
    }

    // ------------------------------------------------------------------ limitation de débit

    public function testTheAccessLimitGivesAClearTemporaryPageAndACalculatedRetryAfter(): void
    {
        $this->env('VERIFICATION_ACCESS_LIMIT', '5');
        $token = $this->token();
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(200, $this->get('/v/'.$token), 'consultation '.($i + 1));
        }

        self::assertSame(429, $this->get('/v/'.$token));

        $retry = (int) $this->client->getResponse()->headers->get('Retry-After');
        self::assertGreaterThanOrEqual(1, $retry);
        self::assertLessThanOrEqual(601, $retry, 'attente calculée, jamais le 900 s fixe du lien bénéficiaire');
        $html = $this->html();
        self::assertStringContainsString('Vérification temporairement indisponible', $html);
        self::assertStringContainsString('Ce n’est pas un refus d’autorisation', $html);
        self::assertStringContainsString('Actualiser', $html);
        foreach ([$token, 'Martin', 'Camille', 'LP-2026', 'GH-482-KT'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html, '429 sans aucune donnée');
        }
        $this->assertProtected('429');
    }

    public function testUnknownWellFormedTokensTripTheFailureCapAndThenBlockEvenAValidToken(): void
    {
        $this->env('VERIFICATION_FAILURE_LIMIT', '3');
        $token = $this->token();
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(404, $this->get('/v/'.str_repeat('z', 31).($i + 2)), 'jeton inconnu '.($i + 1));
        }

        self::assertSame(429, $this->get('/v/'.str_repeat('z', 31).'5'));
        self::assertSame(429, $this->get('/v/'.$token), 'plafond d\'échecs atteint : aucune recherche, même d\'un jeton valide, depuis cette adresse');
        self::assertSame(200, $this->get('/v/'.$token, '198.51.100.99'), 'une autre adresse n\'est pas touchée');
    }

    public function testMalformedTokensNeitherCountAsFailuresNorReachTheDatabase(): void
    {
        $this->env('VERIFICATION_FAILURE_LIMIT', '2');
        $token = $this->token();
        $this->freshKernel();
        $this->get('/v/abc'); // le noyau et les compteurs sont chargés avant la mesure
        $questions = fn (): int => (int) ($this->em()->getConnection()->fetchNumeric("SHOW SESSION STATUS LIKE 'Questions'")[1] ?? 0);
        $before = $questions();

        for ($i = 0; $i < 10; ++$i) {
            self::assertSame(404, $this->get('/v/'.str_repeat('a', 5 + $i)));
        }

        self::assertSame(0, $questions() - $before - 1, 'aucune requête SQL pour un jeton mal formé');
        self::assertSame(200, $this->get('/v/'.$token), 'dix jetons mal formés : le quota d\'échecs est intact');
        self::assertSame(404, $this->get('/v/'.self::UNKNOWN));
        self::assertSame(404, $this->get('/v/'.str_repeat('y', 32)));
        self::assertSame(429, $this->get('/v/'.str_repeat('x', 32)), 'seuls deux inconnus bien formés étaient permis');
    }

    public function testMalformedAndUnknownTokensGetTheSameAnswer(): void
    {
        $this->get('/v/'.self::UNKNOWN);
        $unknown = $this->html();
        $headers = $this->client->getResponse()->headers->all();
        unset($headers['date']);

        $this->get('/v/pas-un-jeton');

        self::assertResponseStatusCodeSame(404);
        self::assertSame($unknown, $this->html());
        $malformed = $this->client->getResponse()->headers->all();
        unset($malformed['date']);
        self::assertSame($headers, $malformed);
    }

    public function testARetiredTokenIsNeverAFailure(): void
    {
        $this->env('VERIFICATION_FAILURE_LIMIT', '2');
        $token = $this->token();
        $this->retired();

        for ($i = 0; $i < 8; ++$i) {
            self::assertSame(410, $this->get('/v/'.$token), 'consultation '.($i + 1));
        }

        self::assertSame(404, $this->get('/v/'.self::UNKNOWN), 'le quota d\'échecs est intact');
        self::assertSame(404, $this->get('/v/'.str_repeat('y', 32)));
        self::assertSame(429, $this->get('/v/'.str_repeat('x', 32)));
    }

    public function testSeveralControllersBehindOneSharedAddressShareTheAccessAllowance(): void
    {
        $this->env('VERIFICATION_ACCESS_LIMIT', '30');
        $token = $this->token();
        for ($scan = 0; $scan < 3; ++$scan) {
            for ($controller = 1; $controller <= 10; ++$controller) {
                self::assertSame(200, $this->get('/v/'.$token, '203.0.113.50', ['HTTP_USER_AGENT' => 'Controleur-'.$controller]), 'contrôleur '.$controller.', scan '.($scan + 1));
            }
        }

        self::assertSame(429, $this->get('/v/'.$token, '203.0.113.50', ['HTTP_USER_AGENT' => 'Controleur-1']), 'trente consultations sur la même IP : le plafond joue pour tous');
        self::assertSame(429, $this->get('/v/'.$token, '203.0.113.50', ['HTTP_USER_AGENT' => 'Controleur-11']));
        self::assertSame(200, $this->get('/v/'.$token, '203.0.113.51'), 'une autre IP a son propre plafond');
    }

    public function testIpv6IsLimitedByPrefixAndAMappedAddressSharesItsIpv4Counter(): void
    {
        $this->env('VERIFICATION_ACCESS_LIMIT', '2');
        $token = $this->token();

        self::assertSame(200, $this->get('/v/'.$token, '2001:db8:1:2::1'));
        self::assertSame(200, $this->get('/v/'.$token, '2001:db8:1:2:ffff:ffff:ffff:ffff'));
        self::assertSame(429, $this->get('/v/'.$token, '2001:db8:1:2:1234::5'), 'trois adresses du même /64 : un seul compteur');
        self::assertSame(200, $this->get('/v/'.$token, '2001:db8:1:3::1'), 'un autre /64 a son compteur');

        self::assertSame(200, $this->get('/v/'.$token, '::ffff:203.0.113.7'));
        self::assertSame(200, $this->get('/v/'.$token, '203.0.113.7'));
        self::assertSame(429, $this->get('/v/'.$token, '::ffff:203.0.113.7'), 'IPv4-mappée et IPv4 : un seul compteur');
    }

    public function testForgedProvenanceHeadersCannotBypassTheLimit(): void
    {
        $this->env('VERIFICATION_ACCESS_LIMIT', '3');
        $token = $this->token();
        for ($i = 1; $i <= 3; ++$i) {
            self::assertSame(200, $this->get('/v/'.$token, '198.51.100.77', [
                'HTTP_X_FORWARDED_FOR' => '10.0.'.$i.'.1',
                'HTTP_FORWARDED' => 'for=10.1.'.$i.'.1',
                'HTTP_X_REAL_IP' => '10.2.'.$i.'.1',
                'HTTP_TRUE_CLIENT_IP' => '10.3.'.$i.'.1',
                'HTTP_CF_CONNECTING_IP' => '10.4.'.$i.'.1',
            ]));
        }

        self::assertSame(429, $this->get('/v/'.$token, '198.51.100.77', ['HTTP_X_FORWARDED_FOR' => '10.9.9.9', 'HTTP_FORWARDED' => 'for=10.9.9.8', 'HTTP_X_REAL_IP' => '10.9.9.7']));
    }

    public function testTheCountersAreIndependentFromTheBeneficiaryLimiters(): void
    {
        $this->env('VERIFICATION_FAILURE_LIMIT', '3');
        $token = $this->token();
        for ($i = 0; $i < 3; ++$i) {
            $this->get('/v/'.str_repeat('z', 31).($i + 2), '203.0.113.60');
        }
        self::assertSame(429, $this->get('/v/'.$token, '203.0.113.60'));

        self::assertSame(404, $this->get('/laissez-passer/'.str_repeat('Q', 43), '203.0.113.60'), 'le lien bénéficiaire n\'est pas bloqué par les échecs du contrôle');

        for ($i = 0; $i < 20; ++$i) {
            $this->get('/laissez-passer/'.str_repeat('R', 42).dechex($i % 16), '203.0.113.61');
        }
        self::assertSame(429, $this->get('/laissez-passer/'.str_repeat('S', 43), '203.0.113.61'), 'témoin : le plafond bénéficiaire est atteint');
        self::assertSame(200, $this->get('/v/'.$token, '203.0.113.61'), 'et ne bloque pas le contrôle public');
    }

    public function testADisabledSwitchConsumesNoCounter(): void
    {
        $this->env('VERIFICATION_ACCESS_LIMIT', '1');
        $token = $this->token();
        $this->env('PUBLIC_VERIFICATION_ENABLED', '0');
        for ($i = 0; $i < 4; ++$i) {
            self::assertSame(404, $this->get('/v/'.$token, '203.0.113.70'));
        }
        $this->env('PUBLIC_VERIFICATION_ENABLED', '1');

        self::assertSame(200, $this->get('/v/'.$token, '203.0.113.70'), 'route coupée : aucun compteur consommé');
    }

    public function testTheLimitsAreProvisionalAndConfigurable(): void
    {
        $services = (string) file_get_contents(\dirname(__DIR__, 3).'/config/services.yaml');
        $framework = (string) file_get_contents(\dirname(__DIR__, 3).'/config/packages/framework.yaml');

        self::assertStringContainsString("env(VERIFICATION_ACCESS_LIMIT): '300'", $services);
        self::assertStringContainsString("env(VERIFICATION_FAILURE_LIMIT): '20'", $services);
        self::assertMatchesRegularExpression("/verification_access:\n\s+policy: sliding_window\n\s+limit: '%env\\(int:VERIFICATION_ACCESS_LIMIT\\)%'\n\s+interval: '10 minutes'/", $framework);
        self::assertMatchesRegularExpression("/verification_failure:\n\s+policy: sliding_window\n\s+limit: '%env\\(int:VERIFICATION_FAILURE_LIMIT\\)%'\n\s+interval: '15 minutes'/", $framework);
        self::assertStringContainsString("trusted_headers: ['x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-port']", $framework);
        self::assertDoesNotMatchRegularExpression('/^\s*trusted_proxies:/m', $framework, 'aucun proxy de confiance déclaré');
    }

    // ------------------------------------------------------------------ panne du stockage

    public function testAStorageOutageGivesAnExplicitObservable503WithoutAnySecret(): void
    {
        $token = $this->token();
        $logFile = (string) tempnam(sys_get_temp_dir(), 'civipass-503');
        ini_set('error_log', $logFile);
        $this->freshKernel(false);
        static::getContainer()->set(VerificationThrottle::class, new class () implements VerificationThrottle {
            public function check(string $clientKey): ?int
            {
                throw new ThrottleStorageUnavailable('Compteurs de limitation indisponibles.', 0, new \RuntimeException('disque plein : /var/share/x'));
            }

            public function recordFailure(string $clientKey): void
            {
                throw new ThrottleStorageUnavailable('Compteurs de limitation indisponibles.');
            }
        });

        $this->get('/v/'.$token);
        $status = $this->client->getResponse()->getStatusCode();
        $html = $this->html();
        $retryAfter = $this->client->getResponse()->headers->get('Retry-After');
        $this->assertProtected('503');
        ini_set('error_log', $this->previousErrorLog);
        $logs = (string) file_get_contents($logFile);
        unlink($logFile);

        self::assertSame(503, $status, 'la vérification est suspendue, jamais ouverte sans limitation');
        self::assertSame('60', $retryAfter);
        self::assertStringContainsString('Vérification temporairement indisponible', $html);
        self::assertStringContainsString('Ce n’est pas un refus d’autorisation', $html);
        foreach ([$token, 'Martin', 'LP-2026', 'disque plein'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
        self::assertStringContainsString('Contrôle public : compteurs de limitation indisponibles (cause : RuntimeException), vérification suspendue (503).', $logs, 'panne observable dans le journal, avec la classe de la cause pour le diagnostic');
        self::assertStringNotContainsString($token, $logs);
        self::assertStringNotContainsString('disque plein', $logs, 'jamais le message de la cause');
    }

    public function testARealUnwritableCounterStoreStopsTheVerificationInsteadOfSilentlyUnlimiting(): void
    {
        $this->freshKernel();
        $failing = new class () extends \Symfony\Component\Cache\Adapter\ArrayAdapter {
            public function save(\Psr\Cache\CacheItemInterface $item): bool
            {
                return false;
            }
        };
        static::getContainer()->set('cache.rate_limiter', $failing);

        $this->get('/v/'.$this->token());

        self::assertResponseStatusCodeSame(503);
        $this->assertProtected('stockage réel en panne');
    }

    // ------------------------------------------------------------------ journaux

    /**
     * @return iterable<string, array{string}>
     */
    public static function neighbouringSecretPaths(): iterable
    {
        yield 'invitation' => ['/invitation/'];
        yield 'réinitialisation' => ['/reset-password/'];
    }

    #[DataProvider('neighbouringSecretPaths')]
    public function testInvitationAndResetSecretsNeverReachTheApplicationLogs(string $prefix): void
    {
        $secret = str_repeat('Qk', 25); // 50 caractères : longueur différente de celle de la route, donc 404 du routeur qui cite le chemin
        $logFile = (string) tempnam(sys_get_temp_dir(), 'civipass-log');
        ini_set('error_log', $logFile);
        $this->env('SHELL_VERBOSITY', '3');
        $this->freshKernel(false);

        $this->get($prefix.$secret);
        $this->client->request('POST', $prefix.$secret);
        ini_set('error_log', $this->previousErrorLog);
        $logs = (string) file_get_contents($logFile);
        unlink($logFile);

        self::assertStringContainsString('NotFoundHttpException', $logs, 'l\'erreur est journalisée (le test n\'est pas vide)');
        self::assertStringNotContainsString($secret, $logs);
        self::assertStringContainsString($prefix.'[masqué]', $logs, 'chemin masqué, pas supprimé');
    }

    public function testNoTokenReachesTheLogsOnThrottledAndRetiredResponses(): void
    {
        $this->env('VERIFICATION_ACCESS_LIMIT', '3');
        $token = $this->token();
        $logFile = (string) tempnam(sys_get_temp_dir(), 'civipass-log');
        ini_set('error_log', $logFile);
        $this->env('SHELL_VERBOSITY', '3');
        $this->freshKernel(false);

        $this->get('/v/'.$token, '203.0.113.80');
        $this->get('/v/'.self::UNKNOWN, '203.0.113.80');
        $this->retired();
        $this->get('/v/'.$token, '203.0.113.80');
        $status429 = $this->get('/v/'.$token, '203.0.113.80');
        $this->get('/v/'.strtoupper($token).'/x', '203.0.113.80');
        ini_set('error_log', $this->previousErrorLog);
        $logs = (string) file_get_contents($logFile);
        unlink($logFile);

        self::assertSame(429, $status429);
        self::assertStringContainsString('[info] Matched route', $logs);
        foreach ([$token, strtoupper($token), '203.0.113.80'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $logs, 'ni jeton ni adresse de client dans les journaux applicatifs');
        }
    }
}
