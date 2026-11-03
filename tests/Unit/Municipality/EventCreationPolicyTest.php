<?php

declare(strict_types=1);

namespace App\Tests\Unit\Municipality;

use App\Municipality\Application\EventCreationPolicy;
use App\Municipality\Application\MunicipalityEntitlementProvider;
use App\Municipality\Domain\Entitlement;
use App\Municipality\Domain\EntitlementKind;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityStatus;
use App\Tests\Support\MunicipalityBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventCreationPolicyTest extends TestCase
{
    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-06-01 10:00:00', new \DateTimeZone('UTC'));
    }

    /**
     * @param list<Entitlement> $entitlements
     */
    private static function policy(array $entitlements): EventCreationPolicy
    {
        return new EventCreationPolicy(new class ($entitlements) implements MunicipalityEntitlementProvider {
            /**
             * @param list<Entitlement> $entitlements
             */
            public function __construct(private readonly array $entitlements)
            {
            }

            public function entitlementsFor(Municipality $municipality, \DateTimeImmutable $at): array
            {
                return $this->entitlements;
            }
        });
    }

    #[DataProvider('matrix')]
    public function testCreateEventRequiresActiveStatusAndAValidEntitlement(MunicipalityStatus $status, EntitlementKind $kind, bool $expected): void
    {
        $municipality = MunicipalityBuilder::aMunicipality()->withStatus($status)->build();

        self::assertSame($expected, self::policy([new Entitlement($kind)])->canCreateEvent($municipality, self::now()));
    }

    /**
     * @return iterable<string, array{MunicipalityStatus, EntitlementKind, bool}>
     */
    public static function matrix(): iterable
    {
        foreach (MunicipalityStatus::cases() as $status) {
            foreach (EntitlementKind::cases() as $kind) {
                yield $status->value.' × '.$kind->value => [$status, $kind, MunicipalityStatus::Active === $status];
            }
        }
    }

    public function testNoEntitlementMeansNoCreation(): void
    {
        self::assertFalse(self::policy([])->canCreateEvent(MunicipalityBuilder::aMunicipality()->build(), self::now()));
    }

    public function testAPaidSubscriptionIsNotRequired(): void
    {
        $municipality = MunicipalityBuilder::aMunicipality()->build();

        self::assertTrue(self::policy([new Entitlement(EntitlementKind::CwsGrant)])->canCreateEvent($municipality, self::now()), 'accès commercial CWS sans expiration');
        self::assertTrue(self::policy([new Entitlement(EntitlementKind::Trial, self::now()->modify('+1 day'))])->canCreateEvent($municipality, self::now()), 'TRIAL valide');
        self::assertTrue(self::policy([new Entitlement(EntitlementKind::Subscription)])->canCreateEvent($municipality, self::now()), 'abonnement actif');
    }

    public function testExpiredEntitlementsDoNotCount(): void
    {
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $expired = new Entitlement(EntitlementKind::CwsGrant, self::now());

        self::assertFalse(self::policy([$expired])->canCreateEvent($municipality, self::now()), 'expiré à l\'instant même');
        self::assertFalse(self::policy([$expired, new Entitlement(EntitlementKind::Trial, self::now()->modify('-1 second'))])->canCreateEvent($municipality, self::now()));
        self::assertTrue(self::policy([$expired, new Entitlement(EntitlementKind::Subscription)])->canCreateEvent($municipality, self::now()), 'un seul droit valide suffit');
    }

    public function testNotSubscribedMunicipalityCanStillJoinAnEvent(): void
    {
        self::assertTrue(self::policy([])->canJoinNewEvent(MunicipalityBuilder::aMunicipality()->build()));
    }

    #[DataProvider('nonActiveStatuses')]
    public function testSuspendedOrInactiveMunicipalityCannotJoinANewEvent(MunicipalityStatus $status): void
    {
        $municipality = MunicipalityBuilder::aMunicipality()->withStatus($status)->build();

        self::assertFalse(self::policy([new Entitlement(EntitlementKind::CwsGrant)])->canJoinNewEvent($municipality));
    }

    /**
     * @return iterable<string, array{MunicipalityStatus}>
     */
    public static function nonActiveStatuses(): iterable
    {
        yield 'SUSPENDED' => [MunicipalityStatus::Suspended];
        yield 'INACTIVE' => [MunicipalityStatus::Inactive];
    }
}
