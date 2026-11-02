<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\WebTestCase;

final class PasswordResetFlowTest extends WebTestCase
{
    private const NEW = 'brand new password';

    public function testLoginPageLinksToTheForgotPasswordPage(): void
    {
        $client = $this->freshClient();
        $crawler = $client->request('GET', '/login');

        self::assertSame('/forgot-password', $crawler->selectLink('Mot de passe oublié ?')->attr('href'));
    }

    /**
     * BrowserKit n'exécute aucun JavaScript : ce parcours complet prouve qu'il fonctionne sans JavaScript.
     */
    public function testCompleteJourneyWorksWithoutJavaScript(): void
    {
        $client = $this->freshClient();
        $this->createAccount('user@example.test', superAdmin: true);

        $link = $this->requestResetLink($client, 'user@example.test');
        self::assertResponseRedirects('/forgot-password/check-email');
        self::assertNotNull($link);

        // Le jeton quitte l'URL dès le contrôle : redirection vers une URL sans jeton.
        $client->request('GET', $this->pathOf($link));
        self::assertResponseRedirects('/reset-password');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="password"][autocomplete="new-password"]');
        self::assertStringNotContainsString(substr($link, -43), (string) $client->getResponse()->getContent());

        $client->submitForm('Enregistrer le mot de passe', ['password' => self::NEW, 'password_confirm' => self::NEW]);
        self::assertResponseRedirects('/reset-password/done');

        // L'email de confirmation de sécurité est envoyé à la fin de cette requête ; il ne contient aucun mot de passe.
        self::assertEmailCount(1);
        $notice = self::emailAt(0);
        self::assertSame('Votre mot de passe Civipass a été modifié', $notice->getSubject());
        self::assertStringNotContainsString(self::NEW, self::bodiesOf($notice));

        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Mot de passe modifié');

        // Ancien mot de passe refusé, nouveau accepté.
        $this->submitLogin($client, 'user@example.test', self::PASSWORD);
        $client->request('GET', '/admin/dashboard');
        self::assertResponseRedirects('/login');
        $this->submitLogin($client, 'user@example.test', self::NEW);
        $client->request('GET', '/admin/dashboard');
        self::assertResponseIsSuccessful();
    }

    public function testResponseIsIdenticalForActiveUnknownAndDisabledAccounts(): void
    {
        $client = $this->freshClient();
        $this->createAccount('active@example.test');
        $this->createAccount('disabled@example.test', active: false);

        $seen = [];
        foreach (['active@example.test', 'unknown@example.test', 'disabled@example.test', 'pas un email'] as $email) {
            $client->request('GET', '/forgot-password');
            $client->submitForm('Envoyer le lien', ['email' => $email]);
            $location = $client->getResponse()->headers->get('Location');
            $status = $client->getResponse()->getStatusCode();
            $client->followRedirect();
            $seen[$email] = [$status, $location, $client->getResponse()->getStatusCode(), (string) $client->getCrawler()->filter('main')->html()];
        }

        self::assertCount(1, array_unique(array_map('serialize', $seen)), 'Statut, redirection et contenu doivent être strictement identiques.');
    }

    public function testOnlyEligibleAccountsReceiveAnEmail(): void
    {
        $client = $this->freshClient();
        $this->createAccount('active@example.test');
        $this->createAccount('disabled@example.test', active: false);

        self::assertNull($this->requestResetLink($client, 'unknown@example.test'));
        self::assertEmailCount(0);
        self::assertNull($this->requestResetLink($client, 'disabled@example.test'));
        self::assertEmailCount(0);
        self::assertNotNull($this->requestResetLink($client, 'active@example.test'));
        self::assertEmailCount(1);
        self::assertSame('active@example.test', self::emailAt(0)->getTo()[0]->getAddress());
    }

    public function testLinkIsBuiltFromConfigurationNeverFromTheHostHeader(): void
    {
        $client = $this->freshClient();
        $this->createAccount('user@example.test');

        $link = $this->requestResetLink($client, 'user@example.test', ['HTTP_HOST' => 'evil.example', 'HTTP_X_FORWARDED_HOST' => 'evil.example']);

        self::assertNotNull($link);
        self::assertStringStartsWith(rtrim((string) ($_ENV['DEFAULT_URI'] ?? $_SERVER['DEFAULT_URI']), '/').'/reset-password/', $link);
        self::assertStringNotContainsString('evil.example', self::bodiesOf(self::emailAt(0)));
    }

    public function testExpiredLinkIsRefusedWithAGenericPage(): void
    {
        $client = $this->freshClient();
        $this->createAccount('user@example.test');
        $link = $this->requestResetLink($client, 'user@example.test');
        $this->em()->getConnection()->executeStatement('UPDATE identity_password_reset_request SET expires_at = ?', ['2000-01-01 00:00:00']);

        $client->request('GET', $this->pathOf((string) $link));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'invalide ou expiré');
    }

    public function testLinkCannotBeUsedTwice(): void
    {
        $client = $this->freshClient();
        $this->createAccount('user@example.test');
        $link = (string) $this->requestResetLink($client, 'user@example.test');
        $client->request('GET', $this->pathOf($link));
        $client->followRedirect();
        $client->submitForm('Enregistrer le mot de passe', ['password' => self::NEW, 'password_confirm' => self::NEW]);
        self::assertResponseRedirects('/reset-password/done');

        $client->request('GET', $this->pathOf($link));

        self::assertSelectorTextContains('main', 'invalide ou expiré');
        $client->request('GET', '/reset-password');
        self::assertResponseRedirects('/forgot-password', null, 'Plus aucun jeton en session.');
    }

    public function testUnknownTokenGivesTheSamePageAsAnExpiredOne(): void
    {
        $client = $this->freshClient();
        $client->request('GET', '/reset-password/'.str_repeat('a', 43));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'invalide ou expiré');
    }

    public function testWeakOrMismatchedPasswordsKeepTheTokenUsable(): void
    {
        $client = $this->freshClient();
        $this->createAccount('user@example.test');
        $link = (string) $this->requestResetLink($client, 'user@example.test');
        $client->request('GET', $this->pathOf($link));
        $client->followRedirect();

        $client->submitForm('Enregistrer le mot de passe', ['password' => 'short', 'password_confirm' => 'short']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role="alert"]', '12 caractères');

        $client->submitForm('Enregistrer le mot de passe', ['password' => self::NEW, 'password_confirm' => 'different password']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role="alert"]', 'ne correspondent pas');

        $client->submitForm('Enregistrer le mot de passe', ['password' => self::NEW, 'password_confirm' => self::NEW]);
        self::assertResponseRedirects('/reset-password/done');
    }

    public function testForgedCsrfTokensAreRejectedOnBothForms(): void
    {
        $client = $this->freshClient();
        $this->createAccount('user@example.test');

        $client->request('GET', '/forgot-password');
        $client->request('POST', '/forgot-password', ['email' => 'user@example.test', '_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(422);
        self::assertEmailCount(0);

        $link = (string) $this->requestResetLink($client, 'user@example.test');
        $client->request('GET', $this->pathOf($link));
        $client->followRedirect();
        $client->request('POST', '/reset-password', ['password' => self::NEW, 'password_confirm' => self::NEW, '_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testPagesAreNotCacheableNorIndexableAndSuppressTheReferer(): void
    {
        $client = $this->freshClient();
        $this->createAccount('user@example.test');
        $link = (string) $this->requestResetLink($client, 'user@example.test');
        $client->request('GET', $this->pathOf($link));
        $client->followRedirect();

        $headers = $client->getResponse()->headers;
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertSame('no-referrer', $headers->get('Referrer-Policy'));
        self::assertStringContainsString('noindex', (string) $headers->get('X-Robots-Tag'));
    }

    public function testOldSessionsAreInvalidatedAfterAReset(): void
    {
        $client = $this->freshClient();
        $this->createAccount('user@example.test', superAdmin: true);

        // Session ouverte sur un premier appareil.
        $this->submitLogin($client, 'user@example.test', self::PASSWORD);
        $client->request('GET', '/admin/dashboard');
        self::assertResponseIsSuccessful();
        $deviceOne = $client->getCookieJar()->all();

        // Réinitialisation depuis un second appareil (autre jar de cookies).
        $client->getCookieJar()->clear();
        $link = (string) $this->requestResetLink($client, 'user@example.test');
        $client->request('GET', $this->pathOf($link));
        $client->followRedirect();
        $client->submitForm('Enregistrer le mot de passe', ['password' => self::NEW, 'password_confirm' => self::NEW]);
        self::assertResponseRedirects('/reset-password/done');

        // Le premier appareil est déconnecté.
        $client->getCookieJar()->clear();
        foreach ($deviceOne as $cookie) {
            $client->getCookieJar()->set($cookie);
        }
        $client->request('GET', '/admin/dashboard');
        self::assertResponseRedirects('/login');
    }

    public function testLoginIsNotAutomaticAfterAReset(): void
    {
        $client = $this->freshClient();
        $this->createAccount('user@example.test', superAdmin: true);
        $link = (string) $this->requestResetLink($client, 'user@example.test');
        $client->request('GET', $this->pathOf($link));
        $client->followRedirect();
        $client->submitForm('Enregistrer le mot de passe', ['password' => self::NEW, 'password_confirm' => self::NEW]);

        $client->request('GET', '/admin/dashboard');

        self::assertResponseRedirects('/login');
    }

    public function testRequestsAreLimitedToThreePerHourPerAddressWithAnIdenticalResponse(): void
    {
        $client = $this->freshClient();
        $this->createAccount('user@example.test');

        $locations = [];
        for ($i = 1; $i <= 5; ++$i) {
            $link = $this->requestResetLink($client, 'user@example.test');
            $locations[] = $client->getResponse()->headers->get('Location');
            if ($i <= 3) {
                self::assertNotNull($link, "demande $i");
            } else {
                self::assertNull($link, "demande $i : abandonnée en silence");
            }
        }
        self::assertCount(1, array_unique($locations), 'Même réponse, limitée ou non.');
    }

    public function testRequestsAreLimitedToTenPerHourPerIpAndForgedForwardedForHeadersDoNotHelp(): void
    {
        $client = $this->freshClient();
        for ($i = 1; $i <= 11; ++$i) {
            $this->createAccount(sprintf('user%d@example.test', $i));
        }

        $sent = 0;
        for ($i = 1; $i <= 11; ++$i) {
            // L'attaquant change d'adresse « d'origine » à chaque requête : l'en-tête client n'est pas cru.
            $server = ['HTTP_X_FORWARDED_FOR' => sprintf('203.0.113.%d', $i), 'HTTP_X_REAL_IP' => sprintf('198.51.100.%d', $i)];
            if (null !== $this->requestResetLink($client, sprintf('user%d@example.test', $i), $server)) {
                ++$sent;
            }
        }

        self::assertSame(10, $sent, '10 par heure et par IP réelle ; le 11e est abandonné.');
    }

    public function testTokenValidationAttemptsAreRateLimited(): void
    {
        $client = $this->freshClient();

        for ($i = 0; $i < 10; ++$i) {
            $client->request('GET', '/reset-password/'.str_repeat('b', 43));
            self::assertResponseIsSuccessful();
        }
        $client->request('GET', '/reset-password/'.str_repeat('b', 43));

        self::assertResponseStatusCodeSame(429);
    }

    public function testAuditEntryOfASuccessfulResetCarriesNoSecret(): void
    {
        $client = $this->freshClient();
        $this->createAccount('user@example.test');
        $link = (string) $this->requestResetLink($client, 'user@example.test');
        $client->request('GET', $this->pathOf($link));
        $client->followRedirect();
        $client->submitForm('Enregistrer le mot de passe', ['password' => self::NEW, 'password_confirm' => self::NEW]);

        $rows = $this->em()->getConnection()->fetchAllAssociative("SELECT previous_value, new_value, origin FROM audit_log WHERE action = 'USER_PASSWORD_RESET'");

        self::assertCount(1, $rows);
        self::assertSame('USER', $rows[0]['origin']);
        self::assertStringNotContainsString(self::NEW, json_encode($rows, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(substr($link, -43), json_encode($rows, \JSON_THROW_ON_ERROR));
    }
}
