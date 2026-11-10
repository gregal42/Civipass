<?php

declare(strict_types=1);

namespace App\Tests\Unit\Permit;

use App\Permit\Domain\VerificationToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Jeton nu du chemin `/v/{jeton}` : normalisé ou rejeté, sans exception ; une URL n'est pas un jeton nu.
 */
final class VerificationTokenNormalizeTest extends TestCase
{
    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz234567';

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function values(): iterable
    {
        yield 'jeton' => [self::TOKEN, self::TOKEN];
        yield 'majuscules' => [strtoupper(self::TOKEN), self::TOKEN];
        yield 'espaces' => [" \n".self::TOKEN."\t", self::TOKEN];
        yield 'URL refusée' => ['https://civipass.example/v/'.self::TOKEN, null];
        yield 'trop court' => [substr(self::TOKEN, 1), null];
        yield 'trop long' => [self::TOKEN.'a', null];
        yield 'hors alphabet base32 (0, 1, 8, 9)' => [str_repeat('0', 32), null];
        yield 'caractère spécial' => [substr(self::TOKEN, 0, 31).'-', null];
        yield 'octet nul' => [substr(self::TOKEN, 0, 31)."\0", null];
        yield 'vide' => ['', null];
        yield 'très long' => [str_repeat('a', 100000), null];
    }

    #[DataProvider('values')]
    public function testNormalize(string $value, ?string $expected): void
    {
        self::assertSame($expected, VerificationToken::normalize($value));
    }
}
