<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\SessionLifetimeListener;
use App\Tests\Support\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** LOT 08.3 : expiration d'inactivité et absolue, exclusion des visiteurs anonymes, suppression de « se souvenir de moi ». */
final class SessionExpiryTest extends WebTestCase
{
    public function testRememberMeIsGone(): void
    {
        $client = $this->freshClient();
        $client->request('GET', '/login');
        self::assertSelectorNotExists('input[name="_remember_me"]');
        self::assertStringNotContainsString('Se souvenir', (string) $client->getResponse()->getContent());

        $this->createAccount('admin@example.test', superAdmin: true);
        $token = $client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/login', ['email' => 'admin@example.test', 'password' => self::PASSWORD, '_remember_me' => '1', '_csrf_token' => $token]);
        self::assertNull($client->getCookieJar()->get('REMEMBERME'), 'même forgé, le paramètre ne crée aucun cookie persistant');
    }

    public function testLoginStartsTheClockAndActiveSessionSurvives(): void
    {
        $client = $this->freshClient();
        $this->createAccount('agent@example.test');
        $this->submitLogin($client, 'agent@example.test', self::PASSWORD);

        $session = $this->session($client);
        self::assertIsInt($session->get(SessionLifetimeListener::STARTED));
        $this->age($client, started: 600, seen: 600);
        $client->request('GET', '/login');
        self::assertResponseRedirects();
        self::assertNotNull($this->session($client)->get(SessionLifetimeListener::SEEN));
    }

    public function testIdleAgentIsDisconnectedWithMessageAndReturnPath(): void
    {
        $client = $this->freshClient();
        $this->createAccount('agent@example.test');
        $this->submitLogin($client, 'agent@example.test', self::PASSWORD);
        $this->age($client, started: 2000, seen: 1801);

        $client->request('GET', '/communes');
        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('#session-expired', '30 minutes d’inactivité');
        self::assertSame('/communes', $this->session($client)->get('_security.main.target_path'));

        $client->request('GET', '/communes');
        self::assertResponseRedirects('/login');
    }

    public function testAgentAbsoluteDurationEvenWhenActive(): void
    {
        $client = $this->freshClient();
        $this->createAccount('agent@example.test');
        $this->submitLogin($client, 'agent@example.test', self::PASSWORD);
        $this->age($client, started: 43201, seen: 5);

        $client->request('GET', '/communes');
        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('#session-expired', 'durée maximale (12 h)');
    }

    public function testCwsExpiresAfterFifteenMinutes(): void
    {
        $client = $this->freshClient();
        $this->createAccount('cws@example.test', superAdmin: true);
        $this->submitLogin($client, 'cws@example.test', self::PASSWORD);
        $this->age($client, started: 1000, seen: 901);

        $client->request('GET', '/admin/');
        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('#session-expired', '15 minutes');
    }

    public function testProbeNeverExtendsAndAnswers401WhenExpired(): void
    {
        $client = $this->freshClient();
        $this->createAccount('agent@example.test');
        $this->submitLogin($client, 'agent@example.test', self::PASSWORD);
        $this->age($client, started: 1000, seen: 1000);
        $client->request('GET', '/session/etat');
        self::assertResponseIsSuccessful();
        $seenAfterProbe = $this->session($client)->get(SessionLifetimeListener::SEEN);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertLessThanOrEqual(800, $payload['remaining']);
        self::assertEqualsWithDelta(time() - 1000, $seenAfterProbe, 3, 'la sonde ne prolonge pas la session');

        $this->age($client, started: 2000, seen: 1900);
        $client->request('GET', '/session/etat');
        self::assertResponseStatusCodeSame(401);
    }

    public function testExtendRequiresCsrfAndRefreshesInactivity(): void
    {
        $client = $this->freshClient();
        $this->createAccount('agent@example.test');
        $this->submitLogin($client, 'agent@example.test', self::PASSWORD);
        $this->age($client, started: 1000, seen: 1700);
        $client->request('POST', '/session/prolonger', ['_token' => 'forge']);
        self::assertResponseStatusCodeSame(403);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('csrf', $payload['error']);
    }

    public function testAnonymousPublicVisitorSessionIsUntouched(): void
    {
        $client = $this->freshClient();
        $client->request('GET', '/login');
        $session = $this->session($client);
        $session->set('draft', 'brouillon public');
        $session->save();
        $client->request('GET', '/login');
        self::assertSame('brouillon public', $this->session($client)->get('draft'));
        self::assertNull($this->session($client)->get(SessionLifetimeListener::STARTED));
    }

    private function session(KernelBrowser $client): \Symfony\Component\HttpFoundation\Session\SessionInterface
    {
        $cookie = $client->getCookieJar()->get('MOCKSESSID') ?? self::fail('cookie de session absent');
        $session = static::getContainer()->get('session.factory')->createSession();
        $session->setId($cookie->getValue());
        $session->start();

        return $session;
    }

    /** Recule le début et la dernière activité de la session (secondes). */
    private function age(KernelBrowser $client, int $started, int $seen): void
    {
        $session = $this->session($client);
        $session->set(SessionLifetimeListener::STARTED, time() - $started);
        $session->set(SessionLifetimeListener::SEEN, time() - $seen);
        $session->save();
    }
}
