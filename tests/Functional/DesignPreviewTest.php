<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Shared\UI\DevUi\DesignPreviewController;
use App\Shared\UI\DevUi\PrototypeData;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Les routes /dev/ui n'existent QU'en environnement dev (trois verrous). Aucune base de données.
 * Le noyau dev est appelé directement (sans client de test, réservé à l'environnement test) avec une session factice.
 */
final class DesignPreviewTest extends WebTestCase
{
    private string $cacheDir = '';
    private ?string $previousCacheDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Cache de noyau NEUF et jetable : les noyaux dev/prod (debug désactivé) ne rafraîchissent pas leur cache. Avec le
        // cache du poste (var/cache/prod), ce test pourrait réussir sur des routes périmées, donc pour une mauvaise raison.
        $this->previousCacheDir = $_SERVER['APP_CACHE_DIR'] ?? null;
        $this->cacheDir = sys_get_temp_dir().'/civipass-kernel-'.bin2hex(random_bytes(6));
        $_SERVER['APP_CACHE_DIR'] = $this->cacheDir;
    }

    protected function tearDown(): void
    {
        // Le noyau dev déclare des proxys de confiance dans l'état STATIQUE de Request : ne jamais le laisser fuiter vers
        // les autres tests (les tests de sécurité supposent qu'aucun en-tête X-Forwarded-* n'est cru).
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        parent::tearDown();
        (new Filesystem())->remove($this->cacheDir);
        null === $this->previousCacheDir ? ($_SERVER['APP_CACHE_DIR'] = null) : ($_SERVER['APP_CACHE_DIR'] = $this->previousCacheDir);
        if (null === $this->previousCacheDir) {
            unset($_SERVER['APP_CACHE_DIR']);
        }
    }

    public function testPreviewRoutesDoNotExistInTheTestEnvironment(): void
    {
        $client = static::createClient();

        foreach (['/dev/ui', '/dev/ui/le-temple/tableau-de-bord', '/dev/ui/components/Button'] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }
        $names = array_keys(static::getContainer()->get(RouterInterface::class)->getRouteCollection()->all());
        self::assertSame([], array_filter($names, static fn (string $n): bool => str_starts_with($n, 'dev_ui_')));
        self::assertFalse(static::getContainer()->has(DesignPreviewController::class), 'Le contrôleur n\'est pas enregistré hors dev (#[When]).');
        self::assertFalse(static::getContainer()->has(PrototypeData::class));
    }

    public function testPreviewRoutesDoNotExistInProduction(): void
    {
        self::ensureKernelShutdown();
        $previous = $_SERVER['APP_SECRET'] ?? '';
        $_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = 'test-prod-secret-not-used';
        try {
            $kernel = static::bootKernel(['environment' => 'prod', 'debug' => false]);
            self::assertSame('prod', $kernel->getEnvironment());
            $routes = $kernel->getContainer()->get('router')->getRouteCollection();
            foreach ($routes as $name => $route) {
                self::assertStringStartsNotWith('dev_ui_', (string) $name);
                self::assertStringNotContainsString('/dev/', $route->getPath(), 'Aucune route /dev/ en production.');
            }
            // Le contrôle n'est pas vacueux : les routes réelles de l'application, elles, existent bien en production.
            foreach (['app_home', 'app_login', 'app_logout', 'app_forgot_password', 'app_reset_password_token', 'sonata_admin_dashboard'] as $real) {
                self::assertNotNull($routes->get($real), $real.' doit exister en production.');
            }
            self::assertFalse($kernel->getContainer()->has(DesignPreviewController::class));
            self::assertFalse($kernel->getContainer()->has(PrototypeData::class));
        } finally {
            self::ensureKernelShutdown();
            $_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = $previous;
        }
    }

    public function testTheRuntimeGuardRefusesAnyNonDevEnvironment(): void
    {
        $kernel = static::bootKernel(['environment' => 'dev', 'debug' => false]);
        $container = $kernel->getContainer();
        $guarded = new DesignPreviewController(new PrototypeData($container->get('router'), new Environment(new ArrayLoader())), 'prod', __DIR__.'/../../src/Shared/UI/DevUi/Resources/catalog.json');

        $calls = [
            'index' => static fn () => $guarded->index(new Request()),
            'component' => static fn () => $guarded->component('Button', new Request()),
            'dashboard' => static fn () => $guarded->dashboard('saumos', new Request()),
            'requests' => static fn () => $guarded->requests('saumos', new Request()),
            'request' => static fn () => $guarded->request('saumos', 'DEM-2026-0155', new Request()),
            'forbidden' => static fn () => $guarded->forbidden(),
            'logout' => static fn () => $guarded->logout(),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                self::fail($name.' : le garde d\'exécution doit refuser hors dev.');
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEveryPreviewScreenRendersInDevWithoutTheDatabase(): void
    {
        $kernel = static::bootKernel(['environment' => 'dev', 'debug' => false]);
        $devRoutes = array_filter(array_keys($kernel->getContainer()->get('router')->getRouteCollection()->all()), static fn (string $n): bool => str_starts_with($n, 'dev_ui_'));
        self::assertCount(7, $devRoutes, 'Les 7 routes de prévisualisation existent en dev (le contrôle prod n\'est pas vacueux).');

        $expected = [
            '/dev/ui' => 200, '/dev/ui?theme=dark' => 200,
            '/dev/ui/le-temple/tableau-de-bord' => 200, '/dev/ui/saumos/tableau-de-bord?bascule=1' => 200,
            '/dev/ui/le-temple/demandes' => 200, '/dev/ui/le-temple/demandes?statut=pending&page=1' => 200,
            '/dev/ui/le-temple/demandes?q=martin&tri=name' => 200, '/dev/ui/le-temple/demandes?q=introuvable' => 200, '/dev/ui/saumos/demandes' => 200,
            '/dev/ui/le-temple/demandes/DEM-2026-0156' => 200, '/dev/ui/le-temple/demandes/DEM-2026-0154' => 200,
            '/dev/ui/le-temple/demandes/DEM-2026-0153' => 200, '/dev/ui/saumos/demandes/DEM-2026-0155' => 200,
            '/dev/ui/saumos/demandes/DEM-2026-0156' => 404, '/dev/ui/saumos/utilisateurs' => 403,
            '/dev/ui/components/Button' => 200, '/dev/ui/components/AppShell?width=375&theme=dark' => 200, '/dev/ui/components/Inconnu' => 404,
        ];
        foreach ($expected as $path => $status) {
            self::assertSame($status, $this->dev($kernel, $path)->getStatusCode(), $path);
        }
    }

    public function testEveryComponentOfTheCatalogHasAWorkingPreviewPage(): void
    {
        $kernel = static::bootKernel(['environment' => 'dev', 'debug' => false]);
        /** @var list<array{name: string}> $catalog */
        $catalog = json_decode((string) file_get_contents(__DIR__.'/../../src/Shared/UI/DevUi/Resources/catalog.json'), true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(66, $catalog); // 56 (UI-01) + 10 composants du LOT UI-02
        foreach ($catalog as $component) {
            self::assertSame(200, $this->dev($kernel, '/dev/ui/components/'.$component['name'])->getStatusCode(), $component['name']);
        }
    }

    public function testPreviewShowsTheFakeDataBannerAndWorkingFilters(): void
    {
        $kernel = static::bootKernel(['environment' => 'dev', 'debug' => false]);

        $page = $this->crawl($kernel, '/dev/ui/le-temple/demandes');
        self::assertStringContainsString('données fictives', $page->filter('.cp-banner')->first()->text());
        self::assertSame('fr', $page->filter('html')->attr('lang'));
        self::assertCount(1, $page->filter('body.cp-root'));
        self::assertGreaterThan(0, $page->filter('link[href*="civipass"]')->count());
        self::assertSame(20, $page->filter('tbody tr')->count(), 'Pagination de 20 lignes.');

        $page = $this->crawl($kernel, '/dev/ui/le-temple/demandes?statut=pending');
        self::assertStringContainsString('18 demandes', $page->filter('.cp-filters__count')->text());
        self::assertSame(18, $page->filter('tbody tr')->count());

        self::assertSame(1, $this->crawl($kernel, '/dev/ui/le-temple/demandes?q=DEM-2026-0156')->filter('tbody tr')->count());

        $page = $this->crawl($kernel, '/dev/ui/le-temple/demandes?q=zzzzzz');
        self::assertStringContainsString('0 demande', $page->filter('.cp-filters__count')->text());
        self::assertGreaterThan(0, $page->filter('.cp-empty')->count());
    }

    public function testDecisionFormsDoNothingAndSayIt(): void
    {
        $kernel = static::bootKernel(['environment' => 'dev', 'debug' => false]);

        $response = $this->dev($kernel, '/dev/ui/le-temple/demandes/DEM-2026-0156', 'POST');
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/dev/ui/le-temple/demandes/DEM-2026-0156?demo=1', $response->headers->get('Location'));

        $page = $this->crawl($kernel, '/dev/ui/le-temple/demandes/DEM-2026-0156?demo=1');
        self::assertStringContainsString('aucune décision n’a été enregistrée', implode(' ', $page->filter('.cp-alert')->each(static fn (Crawler $n): string => $n->text())));
    }

    public function testPreviewLogoutNeverTouchesTheRealSession(): void
    {
        $kernel = static::bootKernel(['environment' => 'dev', 'debug' => false]);

        $page = $this->crawl($kernel, '/dev/ui/le-temple/tableau-de-bord');
        self::assertGreaterThan(0, $page->filter('form[action="/dev/ui/deconnexion"][method="post"] input[name="_csrf_token"]')->count(), 'Menu utilisateur et menu mobile : formulaires POST avec jeton.');
        self::assertCount(0, $page->filter('form[action="/logout"]'));
        $response = $this->dev($kernel, '/dev/ui/deconnexion', 'POST');
        self::assertSame('/dev/ui', $response->headers->get('Location'));
    }

    private function dev(KernelInterface $kernel, string $uri, string $method = 'GET'): Response
    {
        $request = Request::create($uri, $method);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $kernel->handle($request);
    }

    private function crawl(KernelInterface $kernel, string $uri): Crawler
    {
        $response = $this->dev($kernel, $uri);
        self::assertContains($response->getStatusCode(), [200, 403], $uri);

        return new Crawler((string) $response->getContent(), 'http://localhost'.$uri);
    }
}
