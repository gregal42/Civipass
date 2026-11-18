<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ControlEnv;
use App\Tests\Support\WebTestCase;

/**
 * O-6 (LOT 10) : réponses aux liens à jeton INVALIDES (inconnus). Matrice retenue (docs/lot-10-o6-liens-invalides.md) : deux jetons inconnus différents donnent
 * exactement la même réponse (statut, corps, en-têtes de protection) ; le jeton n'est jamais renvoyé ; aucune page ne révèle l'existence d'un compte, d'une
 * demande, d'un laissez-passer ni d'un jeton. Seul un détenteur d'un jeton qui a réellement existé peut voir « utilisé / expiré » (jetons de 160 à 256 bits :
 * aucune découverte par sondage possible).
 */
final class InvalidLinkResponsesTest extends WebTestCase
{
    use ControlEnv;

    protected function setUp(): void
    {
        $this->controlEnv('PUBLIC_VERIFICATION_ENABLED', '1');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->restoreControlEnv();
        parent::tearDown();
    }

    private static function token(int $length, string $seed): string
    {
        return substr(str_repeat(strtr(base64_encode(hash('sha256', $seed, true)), '+/=', '-_A'), 2), 0, $length);
    }

    /** @return list<array{string, int, int, string}> préfixe de route, longueur de jeton, statut attendu, texte attendu */
    public static function flows(): array
    {
        return [
            ['/invitation/', 43, 404, 'Lien introuvable'],
            ['/reset-password/', 43, 200, 'Lien invalide ou expiré'],
            ['/laissez-passer/', 43, 404, 'Ce lien n’est pas valide'],
            ['/v/', 32, 404, 'QR code non reconnu'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('flows')]
    public function testTwoDifferentUnknownTokensGetTheSameNeutralResponse(string $prefix, int $length, int $status, string $text): void
    {
        $responses = [];
        $client = $this->freshClient();
        foreach (['un', 'deux'] as $seed) {
            $token = self::token($length, $seed);
            $client->request('GET', $prefix.$token);
            $response = $client->getResponse();
            $body = (string) preg_replace('/(name="_?(?:csrf_)?token" value=")[^"]+/', '$1', (string) $response->getContent());

            self::assertSame($status, $response->getStatusCode(), $prefix);
            self::assertStringContainsString($text, $body, $prefix);
            self::assertStringNotContainsString($token, (string) $response->getContent(), 'le jeton n’est jamais renvoyé');
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), $prefix);
            self::assertNull($response->headers->get('Set-Cookie') ?? null, 'aucune session pour un lien inconnu : '.$prefix);
            $responses[] = [$response->getStatusCode(), $body];
        }

        self::assertSame($responses[0], $responses[1], 'deux jetons inconnus : réponse identique à l’octet près');
    }

    public function testAMalformedResetTokenGetsAContentlessNotFound(): void
    {
        $client = $this->freshClient();
        $client->request('GET', '/reset-password/trop-court');

        self::assertSame(404, $client->getResponse()->getStatusCode(), 'jeton mal formé : 404 (le corps détaillé n’existe qu’en mode debug)');
    }

    public function testTwoUnknownAddressesGetTheSameResetRequestResponse(): void
    {
        $client = $this->freshClient();
        $answers = [];
        foreach (['inconnu@example.test', 'autre-inconnu@example.test'] as $email) {
            $crawler = $client->request('GET', '/forgot-password');
            $client->request('POST', '/forgot-password', ['email' => $email, '_csrf_token' => $crawler->filter('input[name=_csrf_token]')->attr('value')]);
            $answers[] = [$client->getResponse()->getStatusCode(), $client->getResponse()->headers->get('Location')];
        }

        self::assertSame($answers[0], $answers[1]);
        self::assertStringEndsWith('/forgot-password/check-email', (string) $answers[0][1]);
    }
}
