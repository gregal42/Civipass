<?php

declare(strict_types=1);

namespace App\Tests\Functional\Control;

use App\Control\UI\Http\ControlRouteAllowlist;
use App\Tests\Support\ControlEnv;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;

/**
 * Garde-fous de l'origine de contrôle (LOT 10, G1) : sur l'hôte de contrôle, liste blanche explicite de routes et de méthodes ; tout le reste répond
 * le 404 générique, sans donnée, sans cookie, avec la politique de protection ; les routes de la PWA et la vérification en ligne restent
 * utilisables ; INACTIF tant que CONTROL_HOST est vide.
 */
final class ControlOriginGuardsTest extends WebTestCase
{
    use ControlEnv;

    private const CONTROL = 'controle.civipass.example';
    private const MANAGEMENT = 'civipass.example';

    protected function setUp(): void
    {
        $this->controlEnv('OFFLINE_CONTROL_ENABLED', '1');
        $this->controlEnv('PUBLIC_VERIFICATION_ENABLED', '1');
        $this->controlEnv('CONTROL_HOST', self::CONTROL);
    }

    protected function tearDown(): void
    {
        $this->restoreControlEnv();
        parent::tearDown();
    }

    /**
     * @param array<string, string> $server
     */
    private function call(string $host, string $method, string $path, array $server = []): KernelBrowser
    {
        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->request($method, 'http://'.$host.$path, [], [], ['HTTP_HOST' => $host] + $server);

        return $client;
    }

    /** @return list<array{string, string}> */
    public static function allowedRequests(): array
    {
        return [
            ['GET', '/controle/'], ['GET', '/controle/manifest.webmanifest'], ['GET', '/controle/sw.js'], ['GET', '/controle/js/app.js'], ['GET', '/controle/js/idb-store.js'],
            ['GET', '/controle/api/ping'], ['GET', '/controle/api/device'], ['POST', '/controle/api/activation'], ['POST', '/controle/api/sync'], ['HEAD', '/controle/'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('allowedRequests')]
    public function testEveryRouteOfThePwaStaysServedOnTheControlHost(string $method, string $path): void
    {
        $client = $this->call(self::CONTROL, $method, $path);

        $status = $client->getResponse()->getStatusCode();
        // Le garde-fou ne bloque pas : la réponse vient de l'application (200, ou refus métier 400/401/403/422/429 pour l'API sans identifiants), jamais un 404 générique.
        self::assertNotSame(404, $status, $method.' '.$path);
        self::assertTrue(ControlRouteAllowlist::allows($method, $path));
        self::assertNull($client->getResponse()->headers->get('Set-Cookie'), 'aucun cookie sur l’origine de contrôle : '.$path);
    }

    /** @return list<array{string, string}> */
    public static function deniedRequests(): array
    {
        return [
            ['GET', '/login'], ['POST', '/login'], ['GET', '/communes'], ['GET', '/communes/01a1ef3f-3bab-7303-b66e-19756ad81ba4/demandes'], ['GET', '/admin/dashboard'],
            ['GET', '/v/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'], ['GET', '/demandes/EVT-2026-000001/identite'], ['GET', '/laissez-passer/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'],
            ['GET', '/invitation/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'], ['GET', '/forgot-password'], ['GET', '/'], ['GET', '/dev/ui'], ['GET', '/session/etat'],
            ['GET', '/controle'], ['GET', '/controle/inconnu'], ['GET', '/controle/js/App.js'], ['GET', '/controle/js/../x.js'], ['GET', '/controle/js/a/b.js'],
            ['POST', '/controle/'], ['POST', '/controle/api/ping'], ['GET', '/controle/api/activation'], ['GET', '/controle/api/sync'], ['PUT', '/controle/api/sync'], ['DELETE', '/controle/api/device'],
            ['GET', '/assets/secret.txt'], ['GET', '/assets/app.js'], ['GET', '/favicon.ico'], ['GET', '//login'], ['GET', '/communes/x/evenements/y/controle/code'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deniedRequests')]
    public function testEverythingElseGetsTheGeneric404WithoutDataCookieOrBusinessQuery(string $method, string $path): void
    {
        $client = $this->call(self::CONTROL, $method, $path);
        $response = $client->getResponse();

        self::assertSame(404, $response->getStatusCode(), $method.' '.$path);
        self::assertNull($response->headers->get('Set-Cookie'), 'aucun cookie');
        self::assertSame(0, $client->getCrawler()->filter('form, input')->count(), 'aucune page de l’application');
        self::assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'), 'politique de protection même sur le 404');
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /** @return list<array{string, string}> */
    public static function controlPathsOnTheManagementOrigin(): array
    {
        return [
            ['GET', '/controle/'], ['GET', '/controle'], ['GET', '/controle/sw.js'], ['GET', '/controle/manifest.webmanifest'], ['GET', '/controle/js/app.js'],
            ['GET', '/controle/api/ping'], ['GET', '/controle/api/device'], ['POST', '/controle/api/activation'], ['POST', '/controle/api/sync'], ['GET', '/controle/inconnu'],
        ];
    }

    /** G3 : la PWA, son Service Worker et son API ne sont jamais servis par l'origine de gestion (ni par un autre hôte). */
    #[\PHPUnit\Framework\Attributes\DataProvider('controlPathsOnTheManagementOrigin')]
    public function testTheControlRoutesAreRefusedOnTheManagementOrigin(string $method, string $path): void
    {
        foreach ([self::MANAGEMENT, 'localhost'] as $host) {
            $client = $this->call($host, $method, $path);
            $response = $client->getResponse();

            self::assertSame(404, $response->getStatusCode(), $host.' '.$method.' '.$path);
            self::assertNull($response->headers->get('Set-Cookie'));
            self::assertSame(0, $client->getCrawler()->filter('form, input')->count(), 'aucune page de l’application');
            self::assertStringNotContainsString('cc-app', (string) $response->getContent(), 'jamais la coquille de la PWA');
        }
    }

    public function testTheManagementPagesThatDriveTheControlAreNotTheControlRoutes(): void
    {
        // Les écrans d'administration du contrôle (codes, approbation) restent sur l'origine de gestion, derrière l'authentification.
        $client = $this->call(self::MANAGEMENT, 'GET', '/communes/01a1ef3f-3bab-7303-b66e-19756ad81ba4/evenements/01a1ef40-9087-734c-8893-297a256ac133/controle');

        self::assertSame(302, $client->getResponse()->getStatusCode(), 'redirigé vers la connexion, pas un 404 de G3');
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testTheManagementOriginAndTheOnlineVerificationAreNotAffected(): void
    {
        self::assertSame(200, $this->call(self::MANAGEMENT, 'GET', '/login')->getResponse()->getStatusCode());
        $unknown = $this->call(self::MANAGEMENT, 'GET', '/v/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA')->getResponse();
        self::assertSame(404, $unknown->getStatusCode());
        self::assertStringContainsString('QR code non reconnu', (string) $unknown->getContent(), 'la vérification en ligne répond sur l’origine de gestion, comme avant');
        self::assertSame(200, $this->call('localhost', 'GET', '/login')->getResponse()->getStatusCode(), 'un autre hôte n’est pas concerné par G1');
    }

    public function testTheGuardIsInactiveWhenTheControlHostIsNotSet(): void
    {
        $this->controlEnv('CONTROL_HOST', '');

        self::assertSame(200, $this->call(self::CONTROL, 'GET', '/login')->getResponse()->getStatusCode());
        self::assertSame(200, $this->call(self::CONTROL, 'GET', '/controle/')->getResponse()->getStatusCode());
        self::assertSame(200, $this->call(self::MANAGEMENT, 'GET', '/controle/')->getResponse()->getStatusCode(), 'G3 inactif aussi : le développement local sert /controle/ sous localhost');
    }

    public function testTheHostComparisonIgnoresCaseAndPortButNeverAcceptsALookalike(): void
    {
        self::assertSame(404, $this->call('CONTROLE.CIVIPASS.EXAMPLE', 'GET', '/login')->getResponse()->getStatusCode(), 'même hôte, casse différente');
        self::assertSame(404, $this->call(self::CONTROL.':8443', 'GET', '/login')->getResponse()->getStatusCode(), 'même hôte avec port');
        self::assertSame(200, $this->call('controle.civipass.example.evil.example', 'GET', '/login')->getResponse()->getStatusCode(), 'un hôte différent n’est pas l’hôte de contrôle (refusé par G2 si actif)');
        self::assertSame(404, $this->call('controle.civipass.example.evil.example', 'GET', '/controle/')->getResponse()->getStatusCode(), 'et ne sert jamais /controle/ (G3)');
    }

    /** Inventaire : toute route `/controle…` de l'application est dans la liste blanche, et aucune autre route n'y est jamais. */
    public function testTheAllowlistCoversEveryControlRouteAndNothingElse(): void
    {
        static::bootKernel();
        $router = static::getContainer()->get(RouterInterface::class);
        $control = 0;
        foreach ($router->getRouteCollection() as $name => $route) {
            $path = (string) preg_replace('/\{[^}]+\}/', 'app', $route->getPath());
            $methods = $route->getMethods() ?: ['GET'];
            $allowed = array_filter($methods, static fn (string $m): bool => ControlRouteAllowlist::allows($m, $path));
            if ('/controle' === $path || str_starts_with($path, '/controle/')) {
                ++$control;
                self::assertSame($methods, array_values($allowed), $name.' : route /controle absente de la liste blanche (ou méthode non listée)');
            } elseif (!str_starts_with($path, '/_') && !str_starts_with($path, '/assets/')) {
                self::assertSame([], array_values($allowed), $name.' : une route de gestion ne doit jamais être servie par l’origine de contrôle');
            }
        }
        self::assertSame(8, $control, 'les huit routes app_control_* publiques de la PWA et de l’API');
        self::assertCount(\count(ControlRouteAllowlist::EXACT) + 1, array_filter(iterator_to_array($router->getRouteCollection()), static fn ($r): bool => str_starts_with($r->getPath(), '/controle')), 'liste blanche exacte + modules de script');
    }
}
