<?php

declare(strict_types=1);

namespace App\Tests\Unit\Municipality;

use App\Municipality\Infrastructure\LibPhoneNumberNormalizer;
use App\Shared\Domain\DomainRuleViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LibPhoneNumberNormalizerTest extends TestCase
{
    #[DataProvider('validNumbers')]
    public function testNormalizesToE164(string $raw, string $expected): void
    {
        self::assertSame($expected, (new LibPhoneNumberNormalizer())->normalize($raw)->e164);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validNumbers(): iterable
    {
        yield 'fixe national espaces' => ['01 23 45 67 89', '+33123456789'];
        yield 'mobile national points' => ['06.12.34.56.78', '+33612345678'];
        yield 'tirets' => ['06-12-34-56-78', '+33612345678'];
        yield 'international +33' => ['+33 6 12 34 56 78', '+33612345678'];
        yield 'international 0033' => ['0033 6 12 34 56 78', '+33612345678'];
        yield '+33 (0)' => ['+33 (0)6 12 34 56 78', '+33612345678'];
        yield 'Réunion fixe national' => ['0262 25 12 34', '+262262251234'];
        yield 'Réunion mobile national' => ['0692 12 34 56', '+262692123456'];
        yield 'Guadeloupe fixe national' => ['0590 82 12 34', '+590590821234'];
        yield 'Martinique mobile national' => ['0696 12 34 56', '+596696123456'];
        yield 'Guyane fixe national' => ['0594 30 12 34', '+594594301234'];
        yield 'Nouvelle-Calédonie international' => ['+687 27 12 34', '+687271234'];
        yield 'Royaume-Uni E.164' => ['+442071838750', '+442071838750'];
        yield 'Belgique' => ['+32 2 345 67 89', '+3223456789'];
    }

    #[DataProvider('invalidNumbers')]
    public function testRejectsInvalidNumbers(string $raw): void
    {
        $this->expectException(DomainRuleViolation::class);
        (new LibPhoneNumberNormalizer())->normalize($raw);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNumbers(): iterable
    {
        yield 'vide' => [''];
        yield 'lettres' => ['06 12 34 56 ab'];
        yield 'trop court' => ['06 12 34'];
        yield 'trop long' => ['06 12 34 56 78 90'];
        yield 'préfixe inexistant' => ['+999 123 456'];
        yield 'zéro initial manquant incohérent' => ['00 00 00 00 00'];
    }
}
