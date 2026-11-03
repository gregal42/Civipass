<?php

declare(strict_types=1);

namespace App\Municipality\Infrastructure;

use App\Municipality\Application\MunicipalityEntitlementProvider;
use App\Municipality\Domain\Municipality;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Agrège toutes les sources de droits étiquetées `app.municipality_entitlement_source`. Ajouter une source
 * (module Abonnements, TRIAL…) = déclarer un service avec cette étiquette : EventCreationPolicy ne change pas.
 * Aucune source enregistrée = aucun droit : refus par défaut.
 */
#[AsAlias(MunicipalityEntitlementProvider::class)]
final readonly class ChainMunicipalityEntitlementProvider implements MunicipalityEntitlementProvider
{
    public const SOURCE_TAG = 'app.municipality_entitlement_source';

    /**
     * @param iterable<MunicipalityEntitlementProvider> $sources
     */
    public function __construct(#[AutowireIterator(self::SOURCE_TAG)] private iterable $sources)
    {
    }

    public function entitlementsFor(Municipality $municipality, \DateTimeImmutable $at): array
    {
        $entitlements = [];
        foreach ($this->sources as $source) {
            foreach ($source->entitlementsFor($municipality, $at) as $entitlement) {
                $entitlements[] = $entitlement;
            }
        }

        return $entitlements;
    }
}
