<?php

declare(strict_types=1);

namespace App\Tests\Unit\Request;

use App\Request\Domain\LicensePlate;
use App\Request\Domain\PlateFormat;
use App\Shared\Domain\DomainRuleViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LicensePlateTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, PlateFormat}>
     */
    public static function validPlates(): iterable
    {
        yield 'SIV avec tirets' => ['AB-123-CD', 'AB123CD', PlateFormat::Siv];
        yield 'SIV minuscules et espaces' => [' ab 123 cd ', 'AB123CD', PlateFormat::Siv];
        yield 'FNI à quatre chiffres' => ['1234 AB 56', '1234AB56', PlateFormat::Fni];
        yield 'FNI à un chiffre' => ['1-A-33', '1A33', PlateFormat::Fni];
        yield 'FNI trois lettres' => ['123 ABC 75', '123ABC75', PlateFormat::Fni];
        yield 'FNI Corse 2A' => ['12 AB 2A', '12AB2A', PlateFormat::Fni];
        yield 'FNI Corse 2B' => ['12 AB 2B', '12AB2B', PlateFormat::Fni];
    }

    #[DataProvider('validPlates')]
    public function testNormalizesAndDetectsTheFormat(string $raw, string $expected, PlateFormat $format): void
    {
        $plate = LicensePlate::fromInput($raw);

        self::assertSame($expected, $plate->value);
        self::assertSame($format, $plate->format);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPlates(): iterable
    {
        yield 'vide' => [''];
        yield 'espaces seuls' => ['  - '];
        yield 'SIV trop court' => ['AB-12-CD'];
        yield 'SIV avec un chiffre en trop' => ['AB-1234-CD'];
        yield 'plaque étrangère' => ['B-XY 1234'];
        yield 'FNI sans département' => ['1234 AB'];
        yield 'FNI à cinq chiffres' => ['12345 AB 56'];
        yield 'caractères spéciaux' => ['AB-123-C!'];
    }

    #[DataProvider('invalidPlates')]
    public function testRejectsAnythingThatIsNotSivOrFni(string $raw): void
    {
        $this->expectException(DomainRuleViolation::class);

        LicensePlate::fromInput($raw);
    }
}
