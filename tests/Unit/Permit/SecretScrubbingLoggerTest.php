<?php

declare(strict_types=1);

namespace App\Tests\Unit\Permit;

use App\Permit\Infrastructure\SecretScrubbingLogger;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Masquage ciblé des chemins qui portent un secret (DT-048) : vérification, lien bénéficiaire, invitation, réinitialisation. Rien d'autre n'est masqué.
 */
final class SecretScrubbingLoggerTest extends TestCase
{
    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz234567';
    private const LONG = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-abcdefg';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function messages(): iterable
    {
        yield 'vérification' => ['No route found for "GET https://h/v/'.self::TOKEN.'/extra"', 'No route found for "GET https://h/v/[masqué]/extra"'];
        yield 'vérification en majuscules' => ['GET /v/'.strtoupper(self::TOKEN), 'GET /v/[masqué]'];
        yield 'lien bénéficiaire' => ['GET /laissez-passer/'.self::LONG.'/pdf', 'GET /laissez-passer/[masqué]/pdf'];
        yield 'invitation' => ['No route found for "GET /invitation/'.self::LONG.'"', 'No route found for "GET /invitation/[masqué]"'];
        yield 'réinitialisation' => ['"POST /reset-password/'.self::LONG.'": Method Not Allowed', '"POST /reset-password/[masqué]": Method Not Allowed'];
        // Variantes constatées sur le vrai journal PHP-FPM / Symfony (LOT 05.4.3 bis) : le routeur cite le chemin brut.
        yield 'préfixe en majuscules' => ['No route found for "GET https://h/V/'.strtoupper(self::TOKEN).'"', 'No route found for "GET https://h/V/[masqué]"'];
        yield 'slashs répétés' => ['No route found for "GET https://h///laissez-passer//'.self::LONG.'"', 'No route found for "GET https://h///laissez-passer//[masqué]"'];
        yield 'slashs répétés avant le jeton' => ['GET /v//'.self::TOKEN, 'GET /v//[masqué]'];
        yield 'préfixe /v/ encodé' => ['GET /%76/'.self::TOKEN.'/extra', 'GET /%76/[masqué]/extra'];
        yield 'invitation en majuscules' => ['"GET /INVITATION/'.self::LONG.'"', '"GET /INVITATION/[masqué]"'];
        yield 'préfixe inconnu dans un message du routeur (jeton opaque)' => ['No route found for "GET https://h/%6Caissez-passer/'.self::LONG.'/extra"', 'No route found for "GET https://h/%6Caissez-passer/[masqué]/extra"'];
        yield 'secret tronqué (jeton de vérification)' => ['GET /v/abc', 'GET /v/[masqué]'];
        yield 'secret tronqué (invitation, 11 caractères)' => ['GET /invitation/IIIIinvitat HTTP/1.1', 'GET /invitation/[masqué] HTTP/1.1'];
        yield 'secret tronqué (réinitialisation, 5 caractères)' => ['GET /reset-password/RRRRr HTTP/1.1', 'GET /reset-password/[masqué] HTTP/1.1'];
        yield 'secret tronqué (lien bénéficiaire, 1 caractère)' => ['GET /laissez-passer/a', 'GET /laissez-passer/[masqué]'];
        yield 'plusieurs secrets' => ['/v/'.self::TOKEN.' puis /invitation/'.self::LONG, '/v/[masqué] puis /invitation/[masqué]'];
    }

    #[DataProvider('messages')]
    public function testSecretPathsAreMasked(string $message, string $expected): void
    {
        self::assertSame($expected, SecretScrubbingLogger::scrubString($message));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function untouched(): iterable
    {
        yield 'route d\'invitation fixe' => ['GET /invitation/delegation'];
        yield 'route d\'invitation fixe (rejoindre)' => ['POST /invitation/rejoindre'];
        yield 'page d\'invitation' => ['GET /invitation'];
        yield 'formulaire de réinitialisation' => ['GET /reset-password'];
        yield 'demande de réinitialisation' => ['GET /forgot-password/check-email'];
        yield 'route de commune' => ['GET /communes/0192f0a0-0000-7000-8000-000000000000/evenements'];
        yield 'route fixe de réinitialisation' => ['GET /reset-password/done'];
        yield 'UUID dans un message du routeur' => ['No route found for "GET /communes/0192f0a0-0000-7000-8000-000000000000/x"'];
        yield 'segment de 27 caractères hors préfixe, hors routeur' => ['GET /autre/'.str_repeat('a', 27)];
        yield 'chaîne base32 hors chemin' => ['hash '.self::TOKEN];
    }

    #[DataProvider('untouched')]
    public function testNothingElseIsMasked(string $message): void
    {
        self::assertSame($message, SecretScrubbingLogger::scrubString($message), 'aucun masquage générique : le diagnostic est conservé');
    }

    public function testContextAndSensitiveKeysAreScrubbedToo(): void
    {
        $inner = new RecordingLogger();
        $logger = new SecretScrubbingLogger($inner);

        $logger->error('x', ['uri' => '/reset-password/'.self::LONG, 'nested' => ['u' => '/invitation/'.self::LONG], 'token' => self::TOKEN, 'route' => 'app_public_verification']);

        $line = $inner->lines[0];
        $context = json_decode(substr($line, (int) strpos($line, '{')), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('/reset-password/[masqué]', $context['uri']);
        self::assertSame('/invitation/[masqué]', $context['nested']['u']);
        self::assertSame('[masqué]', $context['token']);
        self::assertSame('app_public_verification', $context['route'], 'le reste du contexte est conservé');
        self::assertStringNotContainsString(self::TOKEN, $line);
        self::assertStringNotContainsString(self::LONG, $line);
    }
}
