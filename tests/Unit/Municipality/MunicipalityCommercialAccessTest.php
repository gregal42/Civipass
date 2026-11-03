<?php

declare(strict_types=1);

namespace App\Tests\Unit\Municipality;

use App\Municipality\Domain\MunicipalityCommercialAccess;
use App\Municipality\Domain\MunicipalityCommercialAccessRepository;
use App\Shared\Domain\DomainRuleViolation;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\TestCase;

final class MunicipalityCommercialAccessTest extends TestCase
{
    private static function at(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    private static function access(?string $expiresAt = null, string $grantedAt = '2026-06-01 10:00'): MunicipalityCommercialAccess
    {
        return new MunicipalityCommercialAccess(
            MunicipalityBuilder::aMunicipality()->build(),
            UserBuilder::aUser()->superAdmin()->build(),
            self::at($grantedAt),
            null === $expiresAt ? null : self::at($expiresAt),
            '  Démonstration commerciale  ',
            self::at($grantedAt),
        );
    }

    public function testConstruction(): void
    {
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $cws = UserBuilder::aUser()->superAdmin()->build();

        $access = new MunicipalityCommercialAccess($municipality, $cws, self::at('2026-06-01 10:00'), self::at('2026-09-01 00:00'), '  Convention pilote  ', self::at('2026-06-01 10:05'));

        self::assertSame($municipality, $access->municipality());
        self::assertSame($cws, $access->grantedBy());
        self::assertEquals(self::at('2026-06-01 10:00'), $access->grantedAt());
        self::assertEquals(self::at('2026-09-01 00:00'), $access->expiresAt());
        self::assertEquals(self::at('2026-06-01 10:05'), $access->createdAt());
        self::assertSame('Convention pilote', $access->reason());
        self::assertFalse($access->isRevoked());
        self::assertNull($access->revokedAt());
        self::assertNull($access->revokedBy());
    }

    public function testAnAccessWithoutExpirationIsAllowed(): void
    {
        $access = self::access();

        self::assertNull($access->expiresAt());
        self::assertTrue($access->isValidAt(self::at('2026-06-01 10:00')));
        self::assertTrue($access->isValidAt(self::at('2099-01-01 00:00')));
    }

    public function testValidityWindow(): void
    {
        $access = self::access('2026-09-01 00:00');

        self::assertFalse($access->isValidAt(self::at('2026-06-01 09:59:59')), 'pas encore attribué');
        self::assertTrue($access->isValidAt(self::at('2026-06-01 10:00')));
        self::assertTrue($access->isValidAt(self::at('2026-08-31 23:59:59')));
        self::assertFalse($access->isValidAt(self::at('2026-09-01 00:00')), 'expiré');
        self::assertFalse($access->isValidAt(self::at('2027-01-01 00:00')));
    }

    public function testRevocationEndsTheAccessButKeepsItsHistory(): void
    {
        $access = self::access('2026-12-31 00:00');
        $cws = UserBuilder::aUser()->superAdmin()->build();

        $access->revoke($cws, self::at('2026-07-01 12:00'));

        self::assertTrue($access->isRevoked());
        self::assertSame($cws, $access->revokedBy());
        self::assertEquals(self::at('2026-07-01 12:00'), $access->revokedAt());
        self::assertTrue($access->isValidAt(self::at('2026-06-30 12:00')), 'valide avant la révocation (lecture historique)');
        self::assertFalse($access->isValidAt(self::at('2026-07-01 12:00')));
        self::assertFalse($access->isValidAt(self::at('2026-08-01 00:00')));
    }

    public function testAnAccessCannotBeRevokedTwiceNorByANonCwsUser(): void
    {
        $access = self::access();
        $communeAdmin = UserBuilder::aUser()->build();

        try {
            $access->revoke($communeAdmin, self::at('2026-07-01 12:00'));
            self::fail('Seul un super-administrateur CWS révoque.');
        } catch (DomainRuleViolation) {
            self::addToAssertionCount(1);
        }
        self::assertFalse($access->isRevoked());

        $access->revoke(UserBuilder::aUser()->superAdmin()->build(), self::at('2026-07-01 12:00'));
        $this->expectException(DomainRuleViolation::class);
        $access->revoke(UserBuilder::aUser()->superAdmin()->build(), self::at('2026-07-02 12:00'));
    }

    public function testOnlyCwsSuperAdminsGrant(): void
    {
        $this->expectException(DomainRuleViolation::class);
        new MunicipalityCommercialAccess(
            MunicipalityBuilder::aMunicipality()->build(),
            UserBuilder::aUser()->build(),
            self::at('2026-06-01 10:00'),
            null,
            'Raison',
            self::at('2026-06-01 10:00'),
        );
    }

    public function testExpirationMustFollowTheGrantDate(): void
    {
        foreach (['2026-06-01 10:00', '2026-05-01 00:00'] as $expiresAt) {
            try {
                self::access($expiresAt);
                self::fail('Expiration invalide acceptée.');
            } catch (DomainRuleViolation) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testReasonIsMandatoryAndBounded(): void
    {
        foreach (['', '   ', str_repeat('x', MunicipalityCommercialAccess::MAX_REASON_LENGTH + 1)] as $reason) {
            try {
                new MunicipalityCommercialAccess(
                    MunicipalityBuilder::aMunicipality()->build(),
                    UserBuilder::aUser()->superAdmin()->build(),
                    self::at('2026-06-01 10:00'),
                    null,
                    $reason,
                    self::at('2026-06-01 10:00'),
                );
                self::fail('Justification invalide acceptée.');
            } catch (DomainRuleViolation) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSuccessiveAccessesAreIndependent(): void
    {
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $cws = UserBuilder::aUser()->superAdmin()->build();
        $first = new MunicipalityCommercialAccess($municipality, $cws, self::at('2026-01-01 00:00'), self::at('2026-03-01 00:00'), 'Pilote', self::at('2026-01-01 00:00'));
        $second = new MunicipalityCommercialAccess($municipality, $cws, self::at('2026-06-01 00:00'), null, 'Renouvellement', self::at('2026-06-01 00:00'));

        self::assertNotEquals($first->id(), $second->id());
        self::assertFalse($first->isValidAt(self::at('2026-06-15 00:00')));
        self::assertTrue($second->isValidAt(self::at('2026-06-15 00:00')));
        self::assertTrue($first->isValidAt(self::at('2026-02-01 00:00')), 'l\'ancien accès reste consultable');
    }

    public function testNothingCanDeleteAnAccess(): void
    {
        foreach ([MunicipalityCommercialAccess::class, MunicipalityCommercialAccessRepository::class] as $class) {
            foreach ((new \ReflectionClass($class))->getMethods() as $method) {
                self::assertDoesNotMatchRegularExpression('/^(remove|delete|purge|erase)/i', $method->getName(), $class);
            }
        }
    }
}
