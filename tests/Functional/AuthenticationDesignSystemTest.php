<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\WebTestCase;

/**
 * Les pages d'authentification sont des compositions de composants du design system (aucun style « maison »).
 */
final class AuthenticationDesignSystemTest extends WebTestCase
{
    private const LEGACY_CLASSES = ['page', 'card', 'field', 'button', 'alert', 'muted', 'field--check', 'button--link'];

    public function testEveryPublicAuthenticationPageUsesDesignSystemComponents(): void
    {
        $client = $this->freshClient();
        $this->createAccount('user@example.test');
        $link = (string) $this->requestResetLink($client, 'user@example.test');

        $pages = [
            'connexion' => fn () => $client->request('GET', '/login'),
            'mot de passe oublié' => fn () => $client->request('GET', '/forgot-password'),
            'vérifiez votre messagerie' => fn () => $client->request('GET', '/forgot-password/check-email'),
            'lien invalide' => fn () => $client->request('GET', '/reset-password/'.str_repeat('a', 43)),
            'accueil' => fn () => $client->request('GET', '/'),
        ];
        foreach ($pages as $label => $visit) {
            $page = $visit();
            self::assertResponseIsSuccessful($label);
            $this->assertDesignSystemMarkup($page, $label);
        }

        // Formulaire de nouveau mot de passe et confirmation : on suit le vrai parcours.
        $client->request('GET', $this->pathOf($link));
        $page = $client->followRedirect();
        $this->assertDesignSystemMarkup($page, 'nouveau mot de passe');
        self::assertSame(2, $page->filter('.cp-field input[type="password"]')->count());
        $client->submitForm('Enregistrer le mot de passe', ['password' => 'brand new password', 'password_confirm' => 'brand new password']);
        $this->assertDesignSystemMarkup($client->followRedirect(), 'confirmation');
    }

    public function testLoginFormIsBuiltFromTheFieldCheckAndButtonComponents(): void
    {
        $client = $this->freshClient();
        $page = $client->request('GET', '/login');

        self::assertSame(2, $page->filter('form .cp-field label.cp-field__label')->count(), 'Deux champs : composant Field.');
        self::assertSame(2, $page->filter('form .cp-field input.cp-input')->count());
        self::assertSame(1, $page->filter('form label.cp-check input[type="checkbox"]')->count(), 'Composant Check.');
        self::assertSame(1, $page->filter('form button.cp-btn.cp-btn--primary[type="submit"]')->count(), 'Composant Button.');
        self::assertSame(1, $page->filter('.cp-panel h1.cp-panel__title')->count(), 'Composant Panel, un seul h1.');
        self::assertGreaterThan(0, $page->filter('header.cp-public__head .cp-logo')->count(), 'Composant PublicHeader (monogramme Cp).');
    }

    public function testErrorsAreRenderedByTheAlertComponent(): void
    {
        $client = $this->freshClient();
        $this->createAccount('user@example.test');

        $this->submitLogin($client, 'user@example.test', 'wrong password!!');
        $page = $client->followRedirect();

        self::assertSame(1, $page->filter('.cp-alert.cp-alert--error[role="alert"]')->count());
        self::assertSame(2, $page->filter('.cp-field input[aria-invalid="true"]')->count());
    }

    public function testNoStylesheetOutsideTheDesignSystemIsLoaded(): void
    {
        $client = $this->freshClient();
        $page = $client->request('GET', '/login');

        $hrefs = $page->filter('link[rel="stylesheet"]')->each(static fn ($n): string => (string) $n->attr('href'));
        self::assertCount(3, $hrefs);
        self::assertMatchesRegularExpression('~/assets/styles/civipass/tokens-[\w-]+\.css$~', $hrefs[0]);
        self::assertMatchesRegularExpression('~/assets/styles/civipass/civipass-[\w-]+\.css$~', $hrefs[1]);
        self::assertMatchesRegularExpression('~/assets/styles/civipass-app-[\w-]+\.css$~', $hrefs[2]);
        self::assertSame(0, $page->filter('style')->count(), 'Aucune feuille de style incorporée.');
    }

    private function assertDesignSystemMarkup(\Symfony\Component\DomCrawler\Crawler $page, string $label): void
    {
        self::assertSame('fr', $page->filter('html')->attr('lang'), $label);
        self::assertSame(1, $page->filter('body.cp-root')->count(), $label.' : body.cp-root');
        self::assertSame(1, $page->filter('main#main.cp-auth .cp-panel')->count(), $label.' : composant Panel');
        self::assertSame(1, $page->filter('main h1')->count(), $label.' : un seul h1');
        self::assertSame(1, $page->filter('a.cp-skip')->count(), $label.' : lien d\'évitement');
        self::assertSame(1, $page->filter('meta[name="turbo-cache-control"][content="no-cache"]')->count(), $label);
        foreach (self::LEGACY_CLASSES as $class) {
            self::assertSame(0, $page->filter('.'.$class)->count(), $label.' : classe « maison » .'.$class);
        }
    }
}
