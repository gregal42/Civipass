<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\Totp;
use App\Identity\Domain\User;
use App\Tests\Support\ControlEnv;
use App\Tests\Support\TestDatabase;
use App\Tests\Support\UserBuilder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Second facteur TOTP des comptes CWS, de bout en bout (SEC-006) : barrière côté serveur sur toutes les routes, inscription, vérification, codes de secours,
 * limitation des essais, pages invisibles aux autres comptes, jeton CSRF.
 */
final class TwoFactorFlowTest extends WebTestCase
{
    use ControlEnv;

    protected function setUp(): void
    {
        $this->controlEnv('TWO_FACTOR_REQUIRED', '1');
    }

    protected function tearDown(): void
    {
        $this->restoreControlEnv();
        parent::tearDown();
    }

    private function persist(object ...$entities): void
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        TestDatabase::reset($em);
        foreach ($entities as $entity) {
            $em->persist($entity);
        }
        $em->flush();
    }

    private function cws(string $email = 'cws@civipass.example'): User
    {
        $user = UserBuilder::aUser()->withEmail($email)->superAdmin()->build();
        $this->persist($user);

        return $user;
    }

    private function newBrowser(User $user): KernelBrowser
    {
        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($user);

        return $client;
    }

    private function token(KernelBrowser $client, string $name = '_token'): string
    {
        return (string) $client->getCrawler()->filter('input[name='.$name.']')->first()->attr('value');
    }

    /**
     * Parcourt l'inscription et retourne [secret, codes de secours].
     *
     * @return array{string, list<string>}
     */
    private function enroll(KernelBrowser $client): array
    {
        $client->request('GET', '/2fa/activation');
        self::assertResponseIsSuccessful();
        $secret = str_replace(' ', '', trim($client->getCrawler()->filter('#tf-secret')->text()));
        $client->request('POST', '/2fa/activation', ['code' => Totp::code($secret, Totp::stepAt(new \DateTimeImmutable())), '_token' => $this->token($client)]);
        self::assertResponseIsSuccessful();
        $codes = $client->getCrawler()->filter('.cp-codes code')->each(static fn ($node): string => $node->text());

        return [$secret, $codes];
    }

    public function testASuperAdministratorWithoutASecondFactorCanReachNothingButTheEnrollment(): void
    {
        $client = $this->newBrowser($this->cws());

        foreach (['/admin/dashboard', '/admin/', '/communes', '/', '/login'] as $path) {
            $client->request('GET', $path);
            self::assertResponseRedirects('/2fa/activation', 302, $path);
        }
        $client->request('POST', '/admin/cws/communes/ouvrir');
        self::assertResponseRedirects('/2fa/activation', 302, 'une action CWS en écriture est barrée avant tout traitement');
        $client->request('GET', '/2fa/verification');
        self::assertResponseRedirects('/2fa/activation', 302, 'rien à vérifier tant que rien n\'est inscrit');
    }

    public function testEnrollmentConfirmsWithAFirstCodeShowsTenBackupCodesOnceAndOpensTheSession(): void
    {
        $client = $this->newBrowser($this->cws());
        $client->request('GET', '/2fa/activation');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertCount(1, $client->getCrawler()->filter('.cp-qr svg'), 'code à scanner');
        $secret = str_replace(' ', '', trim($client->getCrawler()->filter('#tf-secret')->text()));
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);

        $client->request('POST', '/2fa/activation', ['code' => '000000', '_token' => $this->token($client)]);
        self::assertResponseStatusCodeSame(422);
        $client->request('GET', '/admin/dashboard');
        self::assertResponseRedirects('/2fa/activation', 302, 'un mauvais code n\'ouvre rien');

        [, $codes] = $this->enroll($client);
        self::assertCount(10, $codes);
        $client->request('GET', '/admin/dashboard');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/2fa/activation');
        self::assertResponseRedirects('/login', 302, 'plus d\'inscription : les codes de secours ne sont jamais réaffichés');
    }

    public function testANewSessionMustPassTheChallengeAndAReplayedCodeIsRefused(): void
    {
        $user = $this->cws();
        [$secret] = $this->enroll($this->newBrowser($user));
        $next = Totp::code($secret, Totp::stepAt(new \DateTimeImmutable()) + 1);

        $client = $this->newBrowser($user);
        $client->request('GET', '/admin/dashboard');
        self::assertResponseRedirects('/2fa/verification');
        $client->request('GET', '/2fa/verification');
        self::assertResponseIsSuccessful();
        $client->request('POST', '/2fa/verification', ['code' => '123456', '_token' => $this->token($client)]);
        self::assertResponseStatusCodeSame(422);
        $client->request('GET', '/admin/dashboard');
        self::assertResponseRedirects('/2fa/verification', 302, 'toujours barré après un mauvais code');

        $client->request('GET', '/2fa/verification');
        $client->request('POST', '/2fa/verification', ['code' => $next, '_token' => $this->token($client)]);
        self::assertResponseRedirects('/login');
        $client->request('GET', '/admin/dashboard');
        self::assertResponseIsSuccessful();

        $thief = $this->newBrowser($user);
        $thief->request('GET', '/2fa/verification');
        $thief->request('POST', '/2fa/verification', ['code' => $next, '_token' => $this->token($thief)]);
        self::assertResponseStatusCodeSame(422, 'le même code ne se rejoue pas dans une autre session');
    }

    public function testABackupCodeOpensTheSessionOnceOnly(): void
    {
        $user = $this->cws();
        [, $codes] = $this->enroll($this->newBrowser($user));

        $client = $this->newBrowser($user);
        $client->request('GET', '/2fa/verification');
        $client->request('POST', '/2fa/verification', ['code' => $codes[0], '_token' => $this->token($client)]);
        self::assertResponseRedirects('/login');
        $client->request('GET', '/admin/dashboard');
        self::assertResponseIsSuccessful();

        $again = $this->newBrowser($user);
        $again->request('GET', '/2fa/verification');
        $again->request('POST', '/2fa/verification', ['code' => $codes[0], '_token' => $this->token($again)]);
        self::assertResponseStatusCodeSame(422, 'usage unique');
    }

    public function testAttemptsAreLimitedPerAccount(): void
    {
        $user = $this->cws();
        $this->enroll($this->newBrowser($user));
        $client = $this->newBrowser($user);
        $client->request('GET', '/2fa/verification');

        // 5 essais par compte et par fenêtre, inscription comprise : l'inscription en a consommé un, il en reste 4.
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $client->request('POST', '/2fa/verification', ['code' => '000000', '_token' => $this->token($client)]);
            self::assertResponseStatusCodeSame(422, 'essai '.$attempt);
        }
        $client->request('POST', '/2fa/verification', ['code' => '000000', '_token' => $this->token($client)]);

        self::assertResponseStatusCodeSame(429);
        self::assertNotNull($client->getResponse()->headers->get('Retry-After'));
    }

    public function testThePagesDoNotExistForOtherAccountsAndNeedAnAuthentication(): void
    {
        $agent = UserBuilder::aUser()->withEmail('agent@mairie.example')->build();
        $this->persist($agent);
        $client = $this->newBrowser($agent);
        foreach (['/2fa/activation', '/2fa/verification'] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
            $client->request('POST', $path, ['code' => '123456']);
            self::assertResponseStatusCodeSame(404, 'POST '.$path);
        }
        $client->request('GET', '/communes');
        self::assertFalse(str_contains((string) $client->getResponse()->headers->get('Location'), '/2fa'), 'un compte communal n\'est jamais détourné vers le second facteur');

        static::ensureKernelShutdown();
        $anonymous = static::createClient();
        $anonymous->request('GET', '/2fa/activation');
        self::assertResponseRedirects('/login');
    }

    public function testStateChangingRequestsNeedTheCsrfToken(): void
    {
        $client = $this->newBrowser($this->cws());
        $client->request('GET', '/2fa/activation');
        $secret = str_replace(' ', '', trim($client->getCrawler()->filter('#tf-secret')->text()));

        $client->request('POST', '/2fa/activation', ['code' => Totp::code($secret, Totp::stepAt(new \DateTimeImmutable()))]);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/2fa/activation', ['code' => Totp::code($secret, Totp::stepAt(new \DateTimeImmutable())), '_token' => 'forgé']);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/admin/dashboard');
        self::assertResponseRedirects('/2fa/activation', 302, 'sans jeton, rien n\'est confirmé');
    }

    public function testLogoutRemainsPossibleBeforeTheSecondFactor(): void
    {
        $client = $this->newBrowser($this->cws());
        $client->request('GET', '/2fa/activation');
        $client->request('POST', '/logout', ['_csrf_token' => $this->token($client, '_csrf_token')]);

        self::assertResponseRedirects('/login');
    }
}
