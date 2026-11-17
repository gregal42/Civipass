<?php

declare(strict_types=1);

namespace App\Tests\Functional\Control;

use App\Control\UI\Http\ControlHeadersListener;
use App\Tests\Support\ControlEnv;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * PWA de contrôle (LOT 09.1) : interrupteur à 0 par défaut (404 uniforme), coquille, manifeste, Service Worker, en-têtes de protection, absence de session.
 */
final class ControlPwaTest extends WebTestCase
{
    use ControlEnv;

    protected function tearDown(): void
    {
        $this->restoreControlEnv();
        parent::tearDown();
    }

    /** @return list<array{string}> */
    public static function routes(): array
    {
        return [['/controle/'], ['/controle/manifest.webmanifest'], ['/controle/sw.js'], ['/controle/api/ping']];
    }

    #[DataProvider('routes')]
    public function testEverythingIsAUniform404WhileTheSwitchIsOff(string $uri): void
    {
        $this->controlEnv('OFFLINE_CONTROL_ENABLED', '0');
        $client = static::createClient();
        $client->request('GET', $uri);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame(ControlHeadersListener::CSP, $client->getResponse()->headers->get('Content-Security-Policy'), 'même une 404 est protégée');
        self::assertSame('no-store, private', $client->getResponse()->headers->get('Cache-Control'));
        self::assertEmpty($client->getResponse()->headers->getCookies(), 'aucun cookie');
    }

    public function testTheSwitchIsOffByDefaultInTheEnvironmentFiles(): void
    {
        $env = (string) file_get_contents(\dirname(__DIR__, 3).'/.env');
        self::assertMatchesRegularExpression('/^OFFLINE_CONTROL_ENABLED=0$/m', $env);
        $services = (string) file_get_contents(\dirname(__DIR__, 3).'/config/services.yaml');
        self::assertStringContainsString("env(OFFLINE_CONTROL_ENABLED): '0'", $services);
    }

    public function testTheShellIsServedWithoutPersonalDataSessionOrThirdParty(): void
    {
        $this->controlEnv('OFFLINE_CONTROL_ENABLED', '1');
        $client = static::createClient();
        $client->request('GET', '/controle/');
        $response = $client->getResponse();
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(ControlHeadersListener::CSP, $response->headers->get('Content-Security-Policy'));
        self::assertStringNotContainsString("'unsafe-inline'", (string) $response->headers->get('Content-Security-Policy'));
        self::assertStringNotContainsString("'unsafe-eval'", (string) $response->headers->get('Content-Security-Policy'));
        self::assertSame('camera=(self), microphone=(), geolocation=(), payment=(), usb=()', $response->headers->get('Permissions-Policy'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertEmpty($response->headers->getCookies(), 'le pare-feu control_pwa ne démarre aucune session');
        self::assertStringContainsString('data-verification-base="', $html);
        self::assertStringContainsString('rel="manifest"', $html);
        self::assertDoesNotMatchRegularExpression('#<script(?![^>]*\bsrc=)#i', $html, 'aucun script en ligne');
        self::assertDoesNotMatchRegularExpression('#(src|href)="https?://(?!localhost)#i', $html, 'aucune ressource tierce');
    }

    public function testTheManifestMakesTheAppInstallable(): void
    {
        $this->controlEnv('OFFLINE_CONTROL_ENABLED', '1');
        $client = static::createClient();
        $client->request('GET', '/controle/manifest.webmanifest');
        $manifest = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('standalone', $manifest['display']);
        self::assertSame('/controle/', $manifest['start_url']);
        self::assertSame('/controle/', $manifest['scope']);
        self::assertSame(['192x192', '512x512'], array_column($manifest['icons'], 'sizes'));
        self::assertStringStartsWith('application/manifest+json', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    public function testTheServiceWorkerCachesTheShellOnlyAndNeverTheApi(): void
    {
        $this->controlEnv('OFFLINE_CONTROL_ENABLED', '1');
        $client = static::createClient();
        $client->request('GET', '/controle/sw.js');
        $response = $client->getResponse();
        $js = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/javascript', (string) $response->headers->get('Content-Type'));
        self::assertSame('/controle/', $response->headers->get('Service-Worker-Allowed'));
        self::assertStringContainsString('"/controle/"', $js);
        self::assertStringContainsString('jsQR', $js, 'le décodeur embarqué est conservé pour le mode hors ligne');
        self::assertStringContainsString("url.pathname.startsWith('/controle/api/')) return;", $js, "l'API n'est jamais interceptée");
        foreach (self::precache($js) as $entry) {
            self::assertFalse(str_starts_with($entry, '/controle/api/'), "l'API n'est jamais pré-conservée");
        }
        foreach (['verdict', 'sync', 'dataset', 'idb-store', 'camera'] as $module) {
            self::assertMatchesRegularExpression('#\/controle\/js\/'.$module.'\.js#', $js, 'module hors ligne pré-conservé : '.$module);
        }
        self::assertDoesNotMatchRegularExpression('#https?://#', (string) json_encode(self::precache($js), \JSON_UNESCAPED_SLASHES));
    }

    public function testTheModulesAreServedByAWhitelistedRouteWithRevalidation(): void
    {
        $this->controlEnv('OFFLINE_CONTROL_ENABLED', '1');
        $client = static::createClient();
        $client->request('GET', '/controle/js/app.js');
        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/javascript', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('import { activate', (string) $response->getContent());
        self::assertNotNull($response->headers->get('ETag'));
        self::assertSame(ControlHeadersListener::CSP, $response->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('src="/controle/js/app.js"', $this->shell($client));

        $client->request('GET', '/controle/js/app.js', server: ['HTTP_IF_NONE_MATCH' => $response->headers->get('ETag')]);
        self::assertSame(304, $client->getResponse()->getStatusCode());

        foreach (['/controle/js/inconnu.js', '/controle/js/..%2Fsecret.js', '/controle/js/APP.js', '/controle/js/app.js.map', '/controle/js/a--b.js'] as $bad) {
            $client->request('GET', $bad);
            self::assertSame(404, $client->getResponse()->getStatusCode(), $bad);
        }
    }

    public function testTheModulesAreA404WhileTheSwitchIsOff(): void
    {
        $this->controlEnv('OFFLINE_CONTROL_ENABLED', '0');
        $client = static::createClient();
        $client->request('GET', '/controle/js/app.js');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    private function shell(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): string
    {
        $client->request('GET', '/controle/');

        return (string) $client->getResponse()->getContent();
    }

    public function testThePingGivesTheServerTimeWithoutAnyData(): void
    {
        $this->controlEnv('OFFLINE_CONTROL_ENABLED', '1');
        $client = static::createClient();
        $client->request('GET', '/controle/api/ping');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['t'], array_keys($body));
        self::assertEqualsWithDelta(time(), $body['t'], 5);
        self::assertSame('no-store, private', $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testOnlyGetIsAllowed(): void
    {
        $this->controlEnv('OFFLINE_CONTROL_ENABLED', '1');
        $client = static::createClient();
        $client->request('POST', '/controle/api/ping');

        self::assertSame(405, $client->getResponse()->getStatusCode());
        self::assertSame(ControlHeadersListener::CSP, $client->getResponse()->headers->get('Content-Security-Policy'));
    }

    /** @return list<string> */
    private static function precache(string $js): array
    {
        self::assertSame(1, preg_match('/const PRECACHE = (\[.*?\]);/s', $js, $m));

        return json_decode($m[1], true, 512, \JSON_THROW_ON_ERROR);
    }
}
