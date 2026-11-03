<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Municipality\Application\MunicipalityEntitlementProvider;
use App\Municipality\Domain\Entitlement;
use App\Municipality\Domain\Municipality;

/**
 * Source de droits d'utilisation pilotée par les tests (aucun bypass : sans entrée, aucun droit).
 */
final class FakeEntitlementProvider implements MunicipalityEntitlementProvider
{
    /** @var array<string, list<Entitlement>> */
    private array $entitlements = [];

    public function grant(Municipality $municipality, Entitlement ...$entitlements): void
    {
        $this->entitlements[$municipality->id()->toRfc4122()] = array_values($entitlements);
    }

    public function entitlementsFor(Municipality $municipality, \DateTimeImmutable $at): array
    {
        return $this->entitlements[$municipality->id()->toRfc4122()] ?? [];
    }
}
