<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Domain\PasswordResetRequest;
use App\Identity\Domain\PasswordResetToken;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\TestCase;

final class PasswordResetTokenTest extends TestCase
{
    public function testTokenIs256BitsOfRandomnessEncodedInBase64Url(): void
    {
        $token = PasswordResetToken::generate();

        self::assertSame(43, \strlen($token->plain));
        self::assertTrue(PasswordResetToken::looksValid($token->plain));
        self::assertNotSame($token->plain, PasswordResetToken::generate()->plain);
    }

    public function testOnlyTheSha256HashIsMeantToBeStored(): void
    {
        $token = PasswordResetToken::fromPlain(str_repeat('a', 43));

        self::assertSame(hash('sha256', str_repeat('a', 43)), $token->hash());
        self::assertSame(64, \strlen($token->hash()));
        self::assertStringNotContainsString($token->plain, $token->hash());
    }

    public function testManyTokensDoNotCollide(): void
    {
        $seen = [];
        for ($i = 0; $i < 2000; ++$i) {
            $seen[PasswordResetToken::generate()->plain] = true;
        }
        self::assertCount(2000, $seen);
    }

    public function testMalformedTokensAreRejectedWithoutAnyLookup(): void
    {
        foreach (['', 'short', str_repeat('a', 42), str_repeat('a', 44), str_repeat('+', 43), str_repeat('a', 42).'/', "' OR 1=1 --"] as $bad) {
            self::assertFalse(PasswordResetToken::looksValid($bad), $bad);
        }
    }

    public function testRequestExpiresAfterThirtyMinutes(): void
    {
        $created = new \DateTimeImmutable('2026-03-01 10:00:00', new \DateTimeZone('UTC'));
        $request = new PasswordResetRequest(UserBuilder::aUser()->build(), PasswordResetToken::generate()->hash(), $created);

        self::assertEquals($created->modify('+30 minutes'), $request->expiresAt());
        self::assertTrue($request->isUsableAt($created));
        self::assertTrue($request->isUsableAt($created->modify('+29 minutes 59 seconds')));
        self::assertFalse($request->isUsableAt($created->modify('+30 minutes')));
        self::assertFalse($request->isUsableAt($created->modify('+2 hours')));
    }

    public function testUsedRequestIsNotUsableAndKeepsItsFirstUsageDate(): void
    {
        $created = new \DateTimeImmutable('2026-03-01 10:00:00', new \DateTimeZone('UTC'));
        $hash = PasswordResetToken::generate()->hash();
        $request = new PasswordResetRequest(UserBuilder::aUser()->build(), $hash, $created);

        $request->markUsed($created->modify('+1 minute'));
        $request->markUsed($created->modify('+5 minutes'));

        self::assertFalse($request->isUsableAt($created->modify('+2 minutes')));
        self::assertEquals($created->modify('+1 minute'), $request->usedAt());
        self::assertSame($hash, $request->tokenHash());
    }
}
