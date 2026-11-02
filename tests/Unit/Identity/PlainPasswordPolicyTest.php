<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Domain\PlainPasswordPolicy;
use App\Shared\Domain\DomainRuleViolation;
use PHPUnit\Framework\TestCase;

final class PlainPasswordPolicyTest extends TestCase
{
    public function testElevenCharactersAreRejected(): void
    {
        $this->expectException(DomainRuleViolation::class);
        PlainPasswordPolicy::assertValid(str_repeat('a', 11));
    }

    public function testTwelveCharactersAreAcceptedWithoutComplexityRule(): void
    {
        PlainPasswordPolicy::assertValid('aaaaaaaaaaaa');
        $this->addToAssertionCount(1);
    }

    public function testCountsCharactersNotBytes(): void
    {
        $this->expectException(DomainRuleViolation::class);
        PlainPasswordPolicy::assertValid(str_repeat('é', 11));
    }

    public function testTooLongPasswordIsRejected(): void
    {
        $this->expectException(DomainRuleViolation::class);
        PlainPasswordPolicy::assertValid(str_repeat('a', 4097));
    }

    public function testMaximumIsMeasuredInBytesLikeTheHasher(): void
    {
        // 2048 × « é » (2 octets) = 4096 octets : accepté ; 2049 caractères = 4098 octets : refusé,
        // alors qu'ils comptent moins de 4096 caractères.
        PlainPasswordPolicy::assertValid(str_repeat('é', 2048));

        $this->expectException(DomainRuleViolation::class);
        PlainPasswordPolicy::assertValid(str_repeat('é', 2049));
    }

    public function testMultibyteEmojiPasswordOverTheByteLimitIsRejectedBeforeHashing(): void
    {
        $password = str_repeat('😀', 1025); // 1025 caractères, 4100 octets
        self::assertLessThan(4096, mb_strlen($password));

        $this->expectException(DomainRuleViolation::class);
        PlainPasswordPolicy::assertValid($password);
    }
}
