<?php

declare(strict_types=1);

namespace App\Municipality\Infrastructure;

use App\Municipality\Application\MunicipalityEntitlementProvider;
use App\Municipality\Domain\Entitlement;
use App\Municipality\Domain\EntitlementKind;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityCommercialAccessRepository;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Source « accès commercial CWS » : lit la persistance minimale (DT-030). Ne contourne rien : chaque accès vise
 * une commune précise, expire ou se révoque, et le statut administratif reste contrôlé par EventCreationPolicy.
 */
#[AutoconfigureTag(ChainMunicipalityEntitlementProvider::SOURCE_TAG)]
final readonly class CommercialAccessEntitlementSource implements MunicipalityEntitlementProvider
{
    public function __construct(private MunicipalityCommercialAccessRepository $accesses)
    {
    }

    public function entitlementsFor(Municipality $municipality, \DateTimeImmutable $at): array
    {
        return array_map(
            static fn ($access): Entitlement => new Entitlement(EntitlementKind::CwsGrant, $access->expiresAt()),
            $this->accesses->findValidAt($municipality->id(), $at),
        );
    }
}
