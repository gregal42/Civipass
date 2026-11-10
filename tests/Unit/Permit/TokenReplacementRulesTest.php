<?php

declare(strict_types=1);

namespace App\Tests\Unit\Permit;

use App\Event\Domain\EventStatus;
use App\Permit\Domain\TokenReplacementRules;
use App\Permit\Domain\VerificationToken;
use App\Shared\Domain\DomainRuleViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Règles pures du remplacement du QR (LOT 05.5.5) : états, remplacement rapproché, seuils de signalement, référence ressaisie, jeton dans un commentaire. */
final class TokenReplacementRulesTest extends TestCase
{
    private static function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    public function testASuspendedEventIsAllowedAndAClosedEventOrARevokedAuthorizationIsRefused(): void
    {
        TokenReplacementRules::assertAllowed(false, EventStatus::Active);
        TokenReplacementRules::assertAllowed(false, EventStatus::Suspended);
        foreach ([[true, EventStatus::Active, 'révoquée'], [false, EventStatus::Closed, 'clos'], [true, EventStatus::Suspended, 'révoquée']] as [$revoked, $status, $word]) {
            try {
                TokenReplacementRules::assertAllowed($revoked, $status);
                self::fail('Refus attendu.');
            } catch (DomainRuleViolation $e) {
                self::assertStringContainsString($word, $e->getMessage());
            }
        }
    }

    public function testASecondReplacementWithinTheHourIsCloseAndOneAtTheLimitIsNot(): void
    {
        $last = self::at('2026-06-12 09:00:00');

        self::assertFalse(TokenReplacementRules::isClose(null, self::at('2026-06-12 09:10:00')));
        self::assertTrue(TokenReplacementRules::isClose($last, self::at('2026-06-12 09:59:59')));
        self::assertFalse(TokenReplacementRules::isClose($last, self::at('2026-06-12 10:00:00')), 'une heure pile : plus rapproché');
    }

    public function testTheUnusualFlagStartsAtThreeReplacementsPerPermitOrTenPerAgentAndNeverBlocks(): void
    {
        self::assertFalse(TokenReplacementRules::isUnusual(2, 9));
        self::assertTrue(TokenReplacementRules::isUnusual(3, 1));
        self::assertTrue(TokenReplacementRules::isUnusual(1, 10));
    }

    public function testTheReferenceMustBeRetypedOnlyForACloseReplacementAndIsNeverEchoed(): void
    {
        TokenReplacementRules::assertReference(false, '', 'LP-2026-0001');
        TokenReplacementRules::assertReference(true, ' lp-2026-0001 ', 'LP-2026-0001');
        try {
            TokenReplacementRules::assertReference(true, 'SAISIE-SECRETE', 'LP-2026-0001');
            self::fail('Référence différente acceptée.');
        } catch (DomainRuleViolation $e) {
            self::assertStringNotContainsString('SAISIE-SECRETE', $e->getMessage());
            self::assertStringNotContainsString('LP-2026-0001', $e->getMessage());
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function comments(): iterable
    {
        $token = VerificationToken::generate();
        yield 'jeton seul' => [$token, true];
        yield 'jeton en majuscules' => [strtoupper($token), true];
        yield 'jeton coupé par des espaces' => [implode(' ', str_split($token, 8)), true];
        yield 'jeton coupé par des tirets' => [implode('-', str_split($token, 4)), true];
        yield 'jeton dans du texte' => ['le QR '.$token.' a été photographié', true];
        yield 'adresse de contrôle' => ['https://exemple.fr/v/abc', true];
        yield 'texte ordinaire' => ['QR photographié sur les réseaux', false];
        yield 'texte avec chiffres' => ['remplacement demandé par téléphone le 12 juin', false];
    }

    #[DataProvider('comments')]
    public function testACommentContainingATokenOrAControlAddressIsDetected(string $comment, bool $expected): void
    {
        self::assertSame($expected, TokenReplacementRules::containsToken($comment));
    }

    public function testTheGeneratedTokenIsNeverTheSameTwice(): void
    {
        $tokens = array_map(static fn (): string => VerificationToken::generate(), range(1, 200));

        self::assertCount(200, array_unique($tokens));
        foreach ($tokens as $token) {
            self::assertMatchesRegularExpression('/^[a-z2-7]{32}$/', $token);
        }
    }
}
