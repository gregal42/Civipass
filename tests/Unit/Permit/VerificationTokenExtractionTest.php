<?php

declare(strict_types=1);

namespace App\Tests\Unit\Permit;

use App\Permit\Domain\VerificationToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le jeton se retrouve dans le contenu d'un QR par une fonction pure (sans réseau, sans base) : prérequis du contrôle hors ligne futur.
 */
final class VerificationTokenExtractionTest extends TestCase
{
    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz234567';

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function scans(): iterable
    {
        yield 'jeton seul' => [self::TOKEN, self::TOKEN];
        yield 'URL' => ['https://civipass.example/v/'.self::TOKEN, self::TOKEN];
        yield 'URL avec slash final' => ['https://civipass.example/v/'.self::TOKEN.'/', self::TOKEN];
        yield 'URL avec paramètres et ancre' => ['https://civipass.example/v/'.self::TOKEN.'?src=qr#x', self::TOKEN];
        yield 'URL avec port' => ['http://localhost:8080/v/'.self::TOKEN, self::TOKEN];
        yield 'lecteur en majuscules' => [strtoupper('https://civipass.example/v/'.self::TOKEN), self::TOKEN];
        yield 'espaces autour' => ["  \n".self::TOKEN."\r\n", self::TOKEN];
        yield 'vide' => ['', null];
        yield 'trop court' => ['https://civipass.example/v/abc', null];
        yield 'trop long' => ['https://civipass.example/v/'.self::TOKEN.'a', null];
        yield 'alphabet invalide' => ['https://civipass.example/v/'.str_repeat('1', 32), null];
        yield 'jeton seulement dans la requête' => ['https://civipass.example/v/?t='.self::TOKEN, null];
        yield 'jeton dans l\'hôte' => ['https://'.self::TOKEN.'/', null];
        yield 'référence publique' => ['LP-2026-0001', null];
        yield 'démesuré' => [str_repeat('a', 5000), null];
    }

    #[DataProvider('scans')]
    public function testExtractsTheTokenWithoutAnyLookup(string $scanned, ?string $expected): void
    {
        self::assertSame($expected, VerificationToken::extract($scanned));
    }

    public function testAGeneratedTokenAlwaysRoundTrips(): void
    {
        for ($i = 0; $i < 50; ++$i) {
            $token = VerificationToken::generate();
            self::assertSame($token, VerificationToken::extract('https://civipass.example/v/'.$token));
            self::assertSame($token, VerificationToken::extract($token));
        }
    }
}
