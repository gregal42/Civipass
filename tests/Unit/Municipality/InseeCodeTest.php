<?php

declare(strict_types=1);

namespace App\Tests\Unit\Municipality;

use App\Municipality\Domain\InseeCode;
use App\Shared\Domain\DomainRuleViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InseeCodeTest extends TestCase
{
    #[DataProvider('validCodes')]
    public function testValidCodes(string $raw, string $expected): void
    {
        self::assertSame($expected, InseeCode::fromString($raw)->value);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validCodes(): iterable
    {
        yield 'métropole' => ['75056', '75056'];
        yield 'Corse du Sud' => ['2A004', '2A004'];
        yield 'Haute-Corse' => ['2B033', '2B033'];
        yield 'DROM' => ['97101', '97101'];
        yield 'minuscules normalisées' => [' 2a004 ', '2A004'];
    }

    #[DataProvider('invalidCodes')]
    public function testInvalidCodes(string $raw): void
    {
        $this->expectException(DomainRuleViolation::class);
        InseeCode::fromString($raw);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCodes(): iterable
    {
        yield 'trop court' => ['7505'];
        yield 'trop long' => ['750560'];
        yield 'lettres' => ['ABCDE'];
        yield '2C inexistant' => ['2C004'];
        yield 'vide' => [''];
    }
}
