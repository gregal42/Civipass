<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\WebTestCase;

/**
 * LOT 11.1 — en-têtes des surfaces à secret en URL (jeton de contrôle, lien bénéficiaire) : même une réponse « inconnue »
 * est non mise en cache, sans référent, non embarquable, sans script ni ressource externe. Les surfaces de gestion
 * (connexion, agent, Sonata) sont l'objet du constat SEC-002 (en-têtes absents) et ne sont donc pas affirmées ici.
 */
final class SecretSurfaceHeadersTest extends WebTestCase
{
    /** @return iterable<string, array{string}> */
    public static function secretUrls(): iterable
    {
        yield 'contrôle public (jeton)' => ['/v/'.str_repeat('a', 43)];
        yield 'laissez-passer bénéficiaire (secret)' => ['/laissez-passer/'.str_repeat('b', 43)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('secretUrls')]
    public function testUnknownSecretResponsesAreLockedDown(string $url): void
    {
        $client = $this->freshClient();
        $client->request('GET', $url);
        $headers = $client->getResponse()->headers;
        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertSame('no-referrer', $headers->get('Referrer-Policy'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        $csp = (string) $headers->get('Content-Security-Policy');
        self::assertStringContainsString("default-src 'none'", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringNotContainsString('script-src', $csp, 'aucun script autorisé sur ces pages');
        self::assertStringContainsString('noindex', (string) $headers->get('X-Robots-Tag'));
    }
}
