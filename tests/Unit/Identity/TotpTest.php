<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Algorithme TOTP (RFC 6238, annexe B : secret ASCII « 12345678901234567890 », HMAC-SHA-1) : les vecteurs de test officiels, tronqués à 6 chiffres.
 */
final class TotpTest extends TestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // base32 de « 12345678901234567890 »

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function rfcVectors(): iterable
    {
        yield 'T=59' => [59, '287082'];
        yield 'T=1111111109' => [1111111109, '081804'];
        yield 'T=1111111111' => [1111111111, '050471'];
        yield 'T=1234567890' => [1234567890, '005924'];
        yield 'T=2000000000' => [2000000000, '279037'];
        yield 'T=20000000000' => [20000000000, '353130'];
    }

    #[DataProvider('rfcVectors')]
    public function testItMatchesTheOfficialTestVectors(int $time, string $expected): void
    {
        self::assertSame($expected, Totp::code(self::SECRET, intdiv($time, Totp::PERIOD)));
    }

    public function testTheToleranceIsOneStepEitherSideAndNoMore(): void
    {
        $now = new \DateTimeImmutable('@1234567890');
        $step = Totp::stepAt($now);

        self::assertSame($step, Totp::match(self::SECRET, Totp::code(self::SECRET, $step), $now));
        self::assertSame($step - 1, Totp::match(self::SECRET, Totp::code(self::SECRET, $step - 1), $now), 'horloge en retard d\'un pas');
        self::assertSame($step + 1, Totp::match(self::SECRET, Totp::code(self::SECRET, $step + 1), $now), 'horloge en avance d\'un pas');
        self::assertNull(Totp::match(self::SECRET, Totp::code(self::SECRET, $step + 2), $now));
        self::assertNull(Totp::match(self::SECRET, Totp::code(self::SECRET, $step - 2), $now));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badInputs(): iterable
    {
        yield 'vide' => [''];
        yield 'trop court' => ['12345'];
        yield 'trop long' => ['1234567'];
        yield 'lettres' => ['abcdef'];
        yield 'espaces seuls' => ['      '];
    }

    #[DataProvider('badInputs')]
    public function testMalformedInputIsNeverAccepted(string $input): void
    {
        self::assertNull(Totp::match(self::SECRET, $input, new \DateTimeImmutable('@1234567890')));
    }

    public function testSpacesInsideTheCodeAreIgnoredAsApplicationsDisplayThemGrouped(): void
    {
        $now = new \DateTimeImmutable('@1234567890');
        $code = Totp::code(self::SECRET, Totp::stepAt($now));

        self::assertNotNull(Totp::match(self::SECRET, substr($code, 0, 3).' '.substr($code, 3), $now));
    }

    public function testGeneratedSecretsAreBase32OfOneHundredSixtyBitsAndDistinct(): void
    {
        $a = Totp::generateSecret();
        $b = Totp::generateSecret();

        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $a);
        self::assertSame(20, \strlen(Totp::base32Decode($a)));
        self::assertNotSame($a, $b);
        self::assertSame('12345678901234567890', Totp::base32Decode(self::SECRET));
        self::assertSame(self::SECRET, Totp::base32Encode('12345678901234567890'));
    }

    public function testTheProvisioningUriCarriesTheStandardParameters(): void
    {
        $uri = Totp::provisioningUri(self::SECRET, 'cws@civipass.example');

        self::assertStringStartsWith('otpauth://totp/Civipass:cws%40civipass.example?', $uri);
        self::assertStringContainsString('secret='.self::SECRET, $uri);
        self::assertStringContainsString('issuer=Civipass', $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
    }

    public function testAnInvalidBase32SecretIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Totp::base32Decode('1!?');
    }
}
