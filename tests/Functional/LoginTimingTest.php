<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\HasherFactoryLog;
use App\Tests\Support\WebTestCase;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * O-6 (LOT 10) — énumération de comptes par le temps de réponse de la connexion, dans le navigateur simulé : un identifiant inconnu fait exécuter un hachage
 * factice par la VRAIE fabrique de hacheurs ; un compte existant, une connexion valide et une tentative limitée n'en exécutent aucun de plus ; les messages,
 * les statuts et le limiteur sont inchangés. (Les mesures de temps avec le coût de production sont documentées dans docs/lot-10-registre-securite.md.)
 */
final class LoginTimingTest extends WebTestCase
{
    private function boot(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = $this->freshClient();
        $client->disableReboot();
        $this->log()->reset();

        return $client;
    }

    private function log(): HasherFactoryLog
    {
        return static::getContainer()->get(HasherFactoryLog::class);
    }

    public function testTheCorrectiveListenerIsRegisteredOnTheRealLoginFailureEvent(): void
    {
        $this->freshClient();
        $dispatcher = static::getContainer()->get('event_dispatcher');
        $classes = array_map(static fn ($l): string => \is_array($l) && \is_object($l[0]) ? $l[0]::class : '', $dispatcher->getListeners(LoginFailureEvent::class));

        self::assertContains(\App\Identity\Infrastructure\Security\UnknownAccountTimingListener::class, $classes);
    }

    public function testAnUnknownIdentifierCostsADummyHashWhileARealAccountCostsOneVerificationOnly(): void
    {
        $client = $this->boot();
        $this->createAccount('admin@example.test', superAdmin: true);
        $this->log()->reset();

        $this->submitLogin($client, 'nobody@example.test', 'mot de passe quelconque');
        $unknown = $this->log()->calls();
        $this->log()->reset();
        $this->submitLogin($client, 'admin@example.test', 'mauvais mot de passe !!');
        $existing = $this->log()->calls();

        self::assertSame(['hash'], $unknown, 'identifiant inconnu : un hachage factice');
        self::assertSame(['verify'], $existing, 'compte existant, mauvais mot de passe : une vérification réelle, rien de plus');
    }

    public function testMessagesAndStatusesAreIdenticalForUnknownAndExistingAccounts(): void
    {
        $client = $this->boot();
        $this->createAccount('admin@example.test', superAdmin: true);
        $answers = [];
        foreach (['nobody@example.test', 'admin@example.test'] as $email) {
            $this->submitLogin($client, $email, 'mauvais mot de passe !!');
            $location = $client->getResponse()->headers->get('Location');
            $status = $client->getResponse()->getStatusCode();
            $client->followRedirect();
            $answers[] = [$status, $location, trim($client->getCrawler()->filter('[role="alert"]')->text())];
        }

        self::assertSame($answers[0], $answers[1]);
        self::assertStringContainsString('Identifiants invalides', $answers[0][2]);
    }

    public function testAValidLoginStillWorksAndCostsOneVerificationOnly(): void
    {
        $client = $this->boot();
        $this->createAccount('admin@example.test', superAdmin: true);
        $this->log()->reset();

        $this->submitLogin($client, 'admin@example.test', self::PASSWORD);

        self::assertSame(['verify'], $this->log()->calls());
        $client->request('GET', '/admin/dashboard');
        self::assertResponseIsSuccessful();
    }

    public function testTheLimiterStillBlocksUnknownIdentifiersWithoutAnyHashOnceExceeded(): void
    {
        $client = $this->boot();
        for ($i = 0; $i < 5; ++$i) {
            $this->submitLogin($client, 'nobody@example.test', 'mauvais mot de passe !!');
        }
        self::assertCount(5, $this->log()->calls(), 'cinq échecs inconnus : cinq hachages factices (le coût d’un vrai compte)');

        $this->submitLogin($client, 'nobody@example.test', 'mauvais mot de passe !!');
        $client->followRedirect();

        self::assertStringContainsString('Trop de tentatives', $client->getCrawler()->filter('[role="alert"]')->text());
        self::assertCount(5, $this->log()->calls(), 'sixième tentative limitée : aucun hachage de plus, le limiteur reste la seule barrière');
    }
}
