<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\WebTestCase;

final class AuthenticationTest extends WebTestCase
{
    public function testLoginPageIsAccessibleAndNotIndexableNorCacheable(): void
    {
        $client = $this->freshClient();
        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSame('fr', $crawler->filter('html')->attr('lang'));
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
        self::assertSelectorExists('meta[name="viewport"]');
        self::assertSelectorExists('main h1');
        self::assertSelectorExists('label[for="email"]');
        self::assertSelectorExists('label[for="password"]');
        self::assertSelectorExists('input#email[type="email"][autocomplete="username"][required]');
        self::assertSelectorExists('input#password[type="password"][autocomplete="current-password"][required]');
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        self::assertGreaterThan(20, \strlen((string) $token), 'Jeton de session attendu, pas un identifiant de jeton stateless.');
        self::assertNotSame('authenticate', $token);
    }

    public function testSuperAdminLogsInAndReachesSonataInFrench(): void
    {
        $client = $this->freshClient();
        $this->createAccount('admin@example.test', superAdmin: true);

        $this->submitLogin($client, 'admin@example.test', self::PASSWORD);
        $client->followRedirects();
        $client->request('GET', '/login');

        self::assertSame('/admin/dashboard', parse_url($client->getRequest()->getUri(), \PHP_URL_PATH));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/logout"] input[name="_csrf_token"]');
        self::assertStringContainsString('Tableau de bord', (string) $client->getResponse()->getContent());
    }

    public function testEmailIsNormalizedAtLogin(): void
    {
        $client = $this->freshClient();
        $this->createAccount('admin@example.test', superAdmin: true);

        $this->submitLogin($client, '  ADMIN@Example.TEST ', self::PASSWORD);
        $client->request('GET', '/admin/dashboard');

        self::assertResponseIsSuccessful();
    }

    public function testUnknownEmailAndWrongPasswordGiveTheSameMessage(): void
    {
        $client = $this->freshClient();
        $this->createAccount('admin@example.test', superAdmin: true);

        $this->submitLogin($client, 'nobody@example.test', self::PASSWORD);
        $client->followRedirect();
        $unknown = $client->getCrawler()->filter('[role="alert"]')->text();

        $this->submitLogin($client, 'admin@example.test', 'wrong password!!');
        $client->followRedirect();
        $wrong = $client->getCrawler()->filter('[role="alert"]')->text();

        self::assertSame($unknown, $wrong);
        self::assertStringContainsString('Identifiants invalides', $wrong);
    }

    public function testDisabledAccountIsRefusedOnlyAfterTheCorrectPassword(): void
    {
        $client = $this->freshClient();
        $this->createAccount('active@example.test', superAdmin: true);
        $this->createAccount('disabled@example.test', active: false);

        // Mauvais mot de passe : rien ne trahit que le compte existe ni qu'il est désactivé.
        $this->submitLogin($client, 'disabled@example.test', 'wrong password!!');
        $client->followRedirect();
        self::assertStringContainsString('Identifiants invalides', $client->getCrawler()->filter('[role="alert"]')->text());

        // Bon mot de passe : refus explicite, aucune session ouverte.
        $this->submitLogin($client, 'disabled@example.test', self::PASSWORD);
        $client->followRedirect();
        self::assertStringContainsString('désactivé', $client->getCrawler()->filter('[role="alert"]')->text());
        $client->request('GET', '/admin/dashboard');
        self::assertResponseRedirects('/login');
    }

    public function testLastLoginIsRecordedWithoutTouchingUpdatedAt(): void
    {
        $client = $this->freshClient();
        $user = $this->createAccount('admin@example.test', superAdmin: true);
        $connection = $this->em()->getConnection();
        $before = $connection->fetchAssociative('SELECT last_login_at, updated_at FROM identity_user WHERE id = ?', [$user->id()->toBinary()]);
        self::assertNull($before['last_login_at']);

        $this->submitLogin($client, 'admin@example.test', self::PASSWORD);

        $after = $connection->fetchAssociative('SELECT last_login_at, updated_at FROM identity_user WHERE id = ?', [$user->id()->toBinary()]);
        self::assertNotNull($after['last_login_at']);
        self::assertSame($before['updated_at'], $after['updated_at']);
    }

    public function testFailedLoginDoesNotRecordLastLogin(): void
    {
        $client = $this->freshClient();
        $user = $this->createAccount('admin@example.test', superAdmin: true);

        $this->submitLogin($client, 'admin@example.test', 'wrong password!!');

        self::assertNull($this->em()->getConnection()->fetchOne('SELECT last_login_at FROM identity_user WHERE id = ?', [$user->id()->toBinary()]));
    }

    public function testMissingOrForgedCsrfTokenIsRejected(): void
    {
        $client = $this->freshClient();
        $this->createAccount('admin@example.test', superAdmin: true);

        $client->request('GET', '/login');
        $client->request('POST', '/login', ['email' => 'admin@example.test', 'password' => self::PASSWORD]);
        $client->followRedirect();
        self::assertStringContainsString('CSRF', $client->getCrawler()->filter('[role="alert"]')->text());

        $client->request('POST', '/login', ['email' => 'admin@example.test', 'password' => self::PASSWORD, '_csrf_token' => 'forged']);
        $client->followRedirect();
        self::assertStringContainsString('CSRF', $client->getCrawler()->filter('[role="alert"]')->text());

        $client->request('GET', '/admin/dashboard');
        self::assertResponseRedirects('/login');
    }

    public function testLoginIsThrottledAfterFiveAttemptsPerIdentifierAndIp(): void
    {
        $client = $this->freshClient();
        $this->createAccount('admin@example.test', superAdmin: true);
        $this->createAccount('other@example.test', superAdmin: true);

        for ($i = 0; $i < 5; ++$i) {
            $this->submitLogin($client, 'admin@example.test', 'wrong password!!');
        }
        // Sixième tentative, même avec le bon mot de passe : bloquée.
        $this->submitLogin($client, 'admin@example.test', self::PASSWORD);
        $client->followRedirect();
        self::assertStringContainsString('Trop de tentatives', $client->getCrawler()->filter('[role="alert"]')->text());
        $client->request('GET', '/admin/dashboard');
        self::assertResponseRedirects('/login');

        // Un autre identifiant depuis la même IP n'est pas affecté (réseau partagé).
        $this->submitLogin($client, 'other@example.test', self::PASSWORD);
        $client->request('GET', '/admin/dashboard');
        self::assertResponseIsSuccessful();
    }

    public function testGlobalIpLimitBoundsTheNumberOfIdentifiersTried(): void
    {
        $client = $this->freshClient();
        $this->createAccount('admin@example.test', superAdmin: true);

        for ($i = 0; $i < 50; ++$i) {
            $this->submitLogin($client, sprintf('guess%d@example.test', $i), 'wrong password!!');
        }
        $this->submitLogin($client, 'admin@example.test', self::PASSWORD);
        $client->followRedirect();

        self::assertStringContainsString('Trop de tentatives', $client->getCrawler()->filter('[role="alert"]')->text());
    }

    public function testSuccessfulLoginResetsTheIdentifierCounterOnly(): void
    {
        $client = $this->freshClient();
        $this->createAccount('admin@example.test', superAdmin: true);

        for ($i = 0; $i < 4; ++$i) {
            $this->submitLogin($client, 'admin@example.test', 'wrong password!!');
        }
        $this->submitLogin($client, 'admin@example.test', self::PASSWORD);
        $client->request('GET', '/admin/dashboard');
        self::assertResponseIsSuccessful();
    }

    public function testLogoutRequiresPostAndAValidCsrfToken(): void
    {
        $client = $this->freshClient();
        $this->createAccount('admin@example.test', superAdmin: true);
        $this->submitLogin($client, 'admin@example.test', self::PASSWORD);

        $client->request('GET', '/logout');
        self::assertResponseStatusCodeSame(405);

        $client->request('POST', '/logout', ['_csrf_token' => 'forged']);
        $client->request('GET', '/admin/dashboard');
        self::assertResponseIsSuccessful('Une déconnexion sans jeton valide ne doit rien déconnecter.');

        $crawler = $client->request('GET', '/');
        $client->submit($crawler->selectButton('Se déconnecter')->form());
        self::assertResponseRedirects('/login');

        $client->request('GET', '/admin/dashboard');
        self::assertResponseRedirects('/login');
    }

    public function testSessionIdentifierChangesAtLogin(): void
    {
        $client = $this->freshClient();
        $this->createAccount('admin@example.test', superAdmin: true);

        $client->request('GET', '/login');
        $before = $client->getCookieJar()->get('MOCKSESSID')?->getValue();
        self::assertNotNull($before);
        $client->submitForm('Se connecter', ['email' => 'admin@example.test', 'password' => self::PASSWORD]);
        $cookie = $client->getCookieJar()->get('MOCKSESSID');

        self::assertNotSame($before, $cookie->getValue(), 'Anti-fixation de session : l\'identifiant doit changer à la connexion.');
    }

    public function testAccountDisabledDuringASessionLosesAccess(): void
    {
        $client = $this->freshClient();
        $user = $this->createAccount('admin@example.test', superAdmin: true);
        $this->submitLogin($client, 'admin@example.test', self::PASSWORD);
        $client->request('GET', '/admin/dashboard');
        self::assertResponseIsSuccessful();

        $this->em()->getConnection()->executeStatement('UPDATE identity_user SET active = 0 WHERE id = ?', [$user->id()->toBinary()]);

        $client->request('GET', '/admin/dashboard');
        self::assertResponseRedirects('/login');
    }

    public function testPlainAccountIsSentHomeAndForbiddenOnAdmin(): void
    {
        $client = $this->freshClient();
        $this->createAccount('agent@example.test');

        $this->submitLogin($client, 'agent@example.test', self::PASSWORD);
        $client->request('GET', '/login');
        self::assertResponseRedirects('/');

        $client->request('GET', '/admin/dashboard');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAuthenticatedUserVisitingLoginIsRedirectedByRole(): void
    {
        $client = $this->freshClient();
        $this->createAccount('admin@example.test', superAdmin: true);
        $this->submitLogin($client, 'admin@example.test', self::PASSWORD);

        $client->request('GET', '/login');

        self::assertResponseRedirects('/admin/dashboard');
    }

    public function testLoginDoesNotRedirectToAnArbitraryExternalUrl(): void
    {
        $client = $this->freshClient();
        $this->createAccount('admin@example.test', superAdmin: true);

        $client->request('GET', '/login');
        $client->submitForm('Se connecter', ['email' => 'admin@example.test', 'password' => self::PASSWORD]);

        self::assertResponseRedirects();
        self::assertStringStartsWith('http://localhost/', (string) $client->getResponse()->headers->get('Location'));
    }
}
