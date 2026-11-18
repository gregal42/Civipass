<?php

declare(strict_types=1);

namespace App\Tests\Functional\Control;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * G2 (LOT 10, vhost de contrôle) : hôtes de confiance. INACTIF par défaut ; renseigné, un en-tête Host qui n'est pas exactement l'un des deux
 * domaines (gestion, contrôle) est refusé (400) avant le routage, sur toute route, sans aucune donnée dans la réponse.
 */
final class TrustedHostsTest extends WebTestCase
{
    private const PATTERN = '^(civipass\.example|controle\.civipass\.example)$';

    protected function tearDown(): void
    {
        putenv('TRUSTED_HOSTS');
        unset($_SERVER['TRUSTED_HOSTS'], $_ENV['TRUSTED_HOSTS']);
        Request::setTrustedHosts([]);
        parent::tearDown();
    }

    private function enable(): void
    {
        putenv('TRUSTED_HOSTS='.self::PATTERN);
        $_SERVER['TRUSTED_HOSTS'] = $_ENV['TRUSTED_HOSTS'] = self::PATTERN;
    }

    private function httpStatus(string $host, string $path = '/login'): int
    {
        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', 'http://'.$host.$path, [], [], ['HTTP_HOST' => $host]);

        return $client->getResponse()->getStatusCode();
    }

    public function testTheGuardIsInactiveByDefaultAnyHostIsServed(): void
    {
        self::assertSame(200, $this->httpStatus('nimporte.quoi.example'));
        self::assertSame(200, $this->httpStatus('localhost'));
    }

    public function testOnlyTheTwoConfiguredDomainsAreServedWhenTheGuardIsOn(): void
    {
        $this->enable();

        self::assertSame(200, $this->httpStatus('civipass.example'));
        self::assertSame(200, $this->httpStatus('controle.civipass.example', '/login'));
        self::assertSame(404, $this->httpStatus('controle.civipass.example', '/controle/'), 'servi par l’application (interrupteur du contrôle à 0 : 404 uniforme), jamais refusé comme hôte');
    }

    public function testAForgedHostIsRefusedBeforeRoutingOnEveryKindOfRoute(): void
    {
        $this->enable();

        foreach (['evil.example', 'civipass.example.evil.example', 'xcivipass.example', 'controle.civipass.example.evil.example'] as $host) {
            foreach (['/login', '/controle/', '/v/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', '/communes', '/demandes/EVT-2026-000001/identite'] as $path) {
                static::ensureKernelShutdown();
                $client = static::createClient();
                $client->request('GET', 'http://'.$host.$path, [], [], ['HTTP_HOST' => $host]);
                self::assertSame(400, $client->getResponse()->getStatusCode(), $host.$path);
                self::assertStringContainsString('Untrusted Host', (string) $client->getCrawler()->filter('title')->text(), 'la page servie est le refus, jamais celle de l’application');
            }
        }
    }
}
