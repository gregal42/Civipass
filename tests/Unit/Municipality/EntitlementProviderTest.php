<?php

declare(strict_types=1);

namespace App\Tests\Unit\Municipality;

use App\Municipality\Application\MunicipalityEntitlementProvider;
use App\Municipality\Domain\Entitlement;
use App\Municipality\Domain\EntitlementKind;
use App\Municipality\Domain\Municipality;
use App\Municipality\Infrastructure\ChainMunicipalityEntitlementProvider;
use App\Tests\Support\MunicipalityBuilder;
use PHPUnit\Framework\TestCase;

final class EntitlementProviderTest extends TestCase
{
    private static function source(Entitlement ...$entitlements): MunicipalityEntitlementProvider
    {
        return new class (array_values($entitlements)) implements MunicipalityEntitlementProvider {
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
        };
    }

    public function testEntitlementValidity(): void
    {
        $at = new \DateTimeImmutable('2026-06-01 10:00:00', new \DateTimeZone('UTC'));

        self::assertTrue((new Entitlement(EntitlementKind::CwsGrant))->isValidAt($at));
        self::assertTrue((new Entitlement(EntitlementKind::Trial, $at->modify('+1 second')))->isValidAt($at));
        self::assertFalse((new Entitlement(EntitlementKind::Trial, $at))->isValidAt($at));
    }

    public function testChainMergesEverySourceAndSourcesCanBeAddedWithoutChangingTheConsumer(): void
    {
        $at = new \DateTimeImmutable('2026-06-01 10:00:00', new \DateTimeZone('UTC'));
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $grant = new Entitlement(EntitlementKind::CwsGrant);
        $trial = new Entitlement(EntitlementKind::Trial, $at->modify('+7 days'));

        $chain = new ChainMunicipalityEntitlementProvider([self::source($grant), self::source(), self::source($trial)]);

        self::assertSame([$grant, $trial], $chain->entitlementsFor($municipality, $at));
    }

    public function testChainWithoutAnySourceDeniesByDefault(): void
    {
        $chain = new ChainMunicipalityEntitlementProvider([]);

        self::assertSame([], $chain->entitlementsFor(MunicipalityBuilder::aMunicipality()->build(), new \DateTimeImmutable()));
    }
}
