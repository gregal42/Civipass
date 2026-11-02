<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RememberMeTest extends WebTestCase
{
    public function testCheckboxIsPresentAndUncheckedByDefault(): void
    {
        $client = $this->freshClient();
        $crawler = $client->request('GET', '/login');

        self::assertSelectorExists('input#remember_me[type="checkbox"][name="_remember_me"]');
        self::assertNull($crawler->filter('input#remember_me')->attr('checked'));
        self::assertSelectorExists('label[for="remember_me"]');
    }

    public function testNoCookieWithoutTheCheckbox(): void
    {
        $client = $this->freshClient();
        $this->createAccount('admin@example.test', superAdmin: true);

        $this->submitLogin($client, 'admin@example.test', self::PASSWORD);

        self::assertNull($client->getCookieJar()->get('REMEMBERME'));
    }

    public function testCookieIsHttpOnlySameSiteLaxAndExpiresWithinThirtyDays(): void
    {
        $client = $this->freshClient();
        $this->createAccount('admin@example.test', superAdmin: true);

        $this->loginRemembered($client);
        $cookie = $this->rememberCookieOfLastResponse($client);

        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', $cookie->getSameSite());
        self::assertFalse($cookie->isSecure(), 'HTTP simple : Secure automatique désactivé.');
        $lifetime = $cookie->getExpiresTime() - time();
        self::assertGreaterThan(29 * 86400, $lifetime);
        self::assertLessThanOrEqual(30 * 86400, $lifetime);
    }

    public function testCookieIsSecureOverHttps(): void
    {
        $client = $this->freshClient();
        $this->createAccount('admin@example.test', superAdmin: true);
        $client->setServerParameter('HTTPS', 'on');

        $this->loginRemembered($client);

        self::assertTrue($this->rememberCookieOfLastResponse($client)->isSecure());
    }

    public function testRememberedVisitorIsRecognizedButNotFullyAuthenticated(): void
    {
        $client = $this->freshClient();
        $this->createAccount('admin@example.test', superAdmin: true);
        $this->loginRemembered($client);

        $this->dropSessionKeepRememberCookie($client);

        $client->request('GET', '/');
        self::assertSelectorTextContains('main', 'admin@example.test');

        // Sonata exige une authentification complète : le cookie ne suffit pas.
        $client->request('GET', '/admin/dashboard');
        self::assertResponseRedirects('/login');

        // La page de connexion s'affiche (pas de boucle de redirection) et permet de saisir le mot de passe.
        $client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        $client->submitForm('Se connecter', ['email' => 'admin@example.test', 'password' => self::PASSWORD]);
        $client->request('GET', '/admin/dashboard');
        self::assertResponseIsSuccessful();
    }

    public function testCookieStopsWorkingAfterAPasswordReset(): void
    {
        $client = $this->freshClient();
        $user = $this->createAccount('admin@example.test', superAdmin: true);
        $this->loginRemembered($client);
        $this->dropSessionKeepRememberCookie($client);
        $client->request('GET', '/');
        self::assertSelectorTextContains('main', 'admin@example.test', 'précondition : le cookie fonctionne');
        $this->dropSessionKeepRememberCookie($client);

        // Réinitialisation réelle (service applicatif), comme le ferait le parcours par email.
        $this->changePasswordThroughTheResetService($user->email());

        $client->request('GET', '/');
        self::assertSelectorNotExists('form[action="/logout"]', 'Le cookie persistant ne doit plus authentifier.');
        self::assertSelectorExists('a[href="/login"]');
    }

    public function testCookieStopsWorkingWhenTheAccountIsDisabled(): void
    {
        $client = $this->freshClient();
        $user = $this->createAccount('admin@example.test', superAdmin: true);
        $this->loginRemembered($client);
        $this->dropSessionKeepRememberCookie($client);

        $this->em()->getConnection()->executeStatement('UPDATE identity_user SET active = 0 WHERE id = ?', [$user->id()->toBinary()]);

        $client->request('GET', '/');
        self::assertSelectorNotExists('form[action="/logout"]');
        self::assertSelectorExists('a[href="/login"]');
        // Et jamais d'accès aux pages protégées.
        $client->request('GET', '/admin/dashboard');
        self::assertResponseRedirects('/login');
    }

    public function testCookieStopsWorkingWhenPrivilegesChange(): void
    {
        $client = $this->freshClient();
        $user = $this->createAccount('admin@example.test', superAdmin: true);
        $this->createAccount('other@example.test', superAdmin: true);
        $this->loginRemembered($client);
        $this->dropSessionKeepRememberCookie($client);

        $this->em()->getConnection()->executeStatement('UPDATE identity_user SET super_admin = 0 WHERE id = ?', [$user->id()->toBinary()]);

        $client->request('GET', '/');
        self::assertSelectorNotExists('form[action="/logout"]');
    }

    public function testATamperedCookieIsRejected(): void
    {
        $client = $this->freshClient();
        $this->createAccount('admin@example.test', superAdmin: true);
        $this->loginRemembered($client);
        $cookie = $client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($cookie);
        $this->dropSessionKeepRememberCookie($client);
        $client->getCookieJar()->set(new BrowserCookie('REMEMBERME', $cookie->getValue().'x', null, '/', 'localhost'));

        $client->request('GET', '/');

        self::assertSelectorNotExists('form[action="/logout"]');
    }

    public function testLogoutDeletesTheCookie(): void
    {
        $client = $this->freshClient();
        $this->createAccount('admin@example.test', superAdmin: true);
        $this->loginRemembered($client);

        $crawler = $client->request('GET', '/');
        $client->submit($crawler->selectButton('Se déconnecter')->form());

        $deleted = array_filter(
            $client->getResponse()->headers->getCookies(),
            static fn ($c): bool => 'REMEMBERME' === $c->getName() && ($c->getExpiresTime() < time() || null === $c->getValue()),
        );
        self::assertNotEmpty($deleted, 'La réponse de déconnexion doit expirer le cookie REMEMBERME.');

        // Le navigateur a bien supprimé le cookie : il ne reste rien pour se reconnecter.
        self::assertNull($client->getCookieJar()->get('REMEMBERME'));
        $client->getCookieJar()->clear();
        $client->request('GET', '/');
        self::assertSelectorNotExists('form[action="/logout"]');
    }

    public function testLastLoginIsRecordedByPasswordLoginOnly(): void
    {
        $client = $this->freshClient();
        $user = $this->createAccount('admin@example.test', superAdmin: true);
        $connection = $this->em()->getConnection();

        $this->loginRemembered($client);
        self::assertNotNull($connection->fetchOne('SELECT last_login_at FROM identity_user WHERE id = ?', [$user->id()->toBinary()]));

        $connection->executeStatement('UPDATE identity_user SET last_login_at = NULL WHERE id = ?', [$user->id()->toBinary()]);
        $this->dropSessionKeepRememberCookie($client);
        $client->request('GET', '/');
        self::assertSelectorTextContains('main', 'admin@example.test', 'précondition : reconnecté par cookie');

        self::assertNull($connection->fetchOne('SELECT last_login_at FROM identity_user WHERE id = ?', [$user->id()->toBinary()]), 'La reconnexion par cookie ne met pas à jour lastLoginAt.');
    }

    private function loginRemembered(KernelBrowser $client): void
    {
        $client->request('GET', '/login');
        $client->submitForm('Se connecter', ['email' => 'admin@example.test', 'password' => self::PASSWORD, '_remember_me' => 'on']);
    }

    private function rememberCookieOfLastResponse(KernelBrowser $client): \Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if ('REMEMBERME' === $cookie->getName()) {
                return $cookie;
            }
        }
        self::fail('Cookie REMEMBERME absent de la réponse de connexion.');
    }

    /**
     * Simule la fin de la session (navigateur fermé) : seul le cookie persistant subsiste.
     */
    private function dropSessionKeepRememberCookie(KernelBrowser $client): void
    {
        $remember = $client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($remember, 'précondition : cookie REMEMBERME présent');
        $client->getCookieJar()->clear();
        $client->getCookieJar()->set($remember);
    }

    private function changePasswordThroughTheResetService(string $email): void
    {
        $service = static::getContainer()->get(\App\Identity\Application\PasswordResetService::class);
        $service->requestReset($email);
        static::getContainer()->get(\App\Identity\Infrastructure\Mail\DeferredMailQueue::class)->flush();
        $body = (string) self::emailAt(0)->getTextBody();
        self::assertSame(1, preg_match('~/reset-password/([A-Za-z0-9_-]{43})~', $body, $m));
        $service->resetPassword($m[1], 'brand new password');
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid(
            $this->em()->getRepository(\App\Identity\Domain\User::class)->findOneBy(['email' => $email]),
            'brand new password',
        ));
    }
}
