<?php

declare(strict_types=1);

namespace App\Tests\Unit\Onboarding;

use App\Onboarding\Application\InvitationDispatcher;
use App\Onboarding\Domain\InvitationToken;
use PHPUnit\Framework\TestCase;

final class InvitationTokenTest extends TestCase
{
    public function testATokenIs256BitsOfRandomnessInBase64Url(): void
    {
        $token = InvitationToken::generate();

        self::assertSame(1, preg_match('/^[A-Za-z0-9_-]{43}$/', $token->plain));
        self::assertSame(32, \strlen((string) base64_decode(strtr($token->plain, '-_', '+/').'=', true)), '32 octets');
        self::assertTrue(InvitationToken::looksValid($token->plain));
    }

    public function testTokensAreUniqueAndOnlyTheirHashIsStable(): void
    {
        $tokens = array_map(static fn (): InvitationToken => InvitationToken::generate(), range(1, 200));

        self::assertCount(200, array_unique(array_map(static fn (InvitationToken $t): string => $t->plain, $tokens)));
        self::assertSame(InvitationToken::fromPlain($tokens[0]->plain)->hash(), $tokens[0]->hash());
        self::assertSame(hash('sha256', $tokens[0]->plain), $tokens[0]->hash());
        self::assertNotSame($tokens[0]->plain, $tokens[0]->hash());
    }

    public function testObviouslyInvalidValuesAreRecognizedWithoutAnyLookup(): void
    {
        foreach (['', 'court', str_repeat('a', 42), str_repeat('a', 44), str_repeat('+', 43), str_repeat('a', 42).' ', "a\n".str_repeat('a', 41)] as $value) {
            self::assertFalse(InvitationToken::looksValid($value), $value);
        }
    }

    public function testMaskedAddressesRevealNeitherTheLocalPartNorAnythingGuessable(): void
    {
        self::assertSame('m***@saumos.example', InvitationDispatcher::mask('mairie@saumos.example'));
        self::assertSame('a***@b.example', InvitationDispatcher::mask('a@b.example'));
        self::assertSame('***', InvitationDispatcher::mask('pas-une-adresse'));
        self::assertSame('***', InvitationDispatcher::mask('@b.example'));
        self::assertStringNotContainsString('airie', InvitationDispatcher::mask('mairie@saumos.example'));
    }
}
