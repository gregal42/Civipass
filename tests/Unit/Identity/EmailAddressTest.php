<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Domain\EmailAddress;
use App\Shared\Domain\DomainRuleViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailAddressTest extends TestCase
{
    public function testNormalizesCaseAndWhitespace(): void
    {
        self::assertSame('jean.dupont+tag@example.fr', EmailAddress::fromString("  Jean.Dupont+Tag@Example.FR \n")->value);
    }

    public function testNormalizesUnicodeToNfcAndKeepsAccents(): void
    {
        $decomposed = "jose\u{0301}@example.fr"; // e + accent combinant
        self::assertSame("jos\u{00e9}@example.fr", EmailAddress::fromString($decomposed)->value);
        self::assertNotSame(EmailAddress::fromString('jose@example.fr')->value, EmailAddress::fromString("jos\u{00e9}@example.fr")->value);
    }

    #[DataProvider('invalidEmails')]
    public function testRejectsInvalidEmails(string $raw): void
    {
        $this->expectException(DomainRuleViolation::class);
        EmailAddress::fromString($raw);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidEmails(): iterable
    {
        yield 'vide' => [''];
        yield 'sans arobase' => ['jean.example.fr'];
        yield 'espace interne' => ['jean dupont@example.fr'];
        yield 'caractère de contrôle' => ["jean\u{0007}@example.fr"];
        yield 'trop long' => [str_repeat('a', 175).'@x.fr'];
    }
}
