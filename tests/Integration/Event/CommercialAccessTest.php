<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Municipality\Application\EventCreationPolicy;
use App\Municipality\Application\MunicipalityEntitlementProvider;
use App\Municipality\Domain\EntitlementKind;
use App\Municipality\Domain\MunicipalityCommercialAccess;
use App\Municipality\Domain\MunicipalityCommercialAccessRepository;
use App\Municipality\Domain\MunicipalityStatus;
use App\Tests\Support\EventSchemaTestCase;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\Group;

#[Group('requires-event-schema')]
final class CommercialAccessTest extends EventSchemaTestCase
{
    private static function at(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    public function testValidityRevocationAndHistory(): void
    {
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $other = MunicipalityBuilder::aMunicipality()->build();
        $cws = UserBuilder::aUser()->superAdmin()->build();
        $expired = new MunicipalityCommercialAccess($municipality, $cws, self::at('2026-01-01 00:00'), self::at('2026-03-01 00:00'), 'Pilote', self::at('2026-01-01 00:00'));
        $revoked = new MunicipalityCommercialAccess($municipality, $cws, self::at('2026-04-01 00:00'), null, 'Démo', self::at('2026-04-01 00:00'));
        $revoked->revoke($cws, self::at('2026-05-01 00:00'));
        $current = new MunicipalityCommercialAccess($municipality, $cws, self::at('2026-06-01 00:00'), null, 'Renouvellement', self::at('2026-06-01 00:00'));
        $future = new MunicipalityCommercialAccess($municipality, $cws, self::at('2026-12-01 00:00'), null, 'Plus tard', self::at('2026-06-01 00:00'));
        $foreign = new MunicipalityCommercialAccess($other, $cws, self::at('2026-06-01 00:00'), null, 'Autre commune', self::at('2026-06-01 00:00'));
        $this->save($municipality, $other, $cws, $expired, $revoked, $current, $future, $foreign);
        $this->em->clear();
        $repository = $this->service(MunicipalityCommercialAccessRepository::class);

        $valid = $repository->findValidAt($municipality->id(), self::at('2026-06-15 00:00'));
        self::assertCount(1, $valid);
        self::assertSame('Renouvellement', $valid[0]->reason());
        self::assertCount(1, $repository->findValidAt($municipality->id(), self::at('2026-02-01 00:00')), 'lecture historique');
        self::assertCount(1, $repository->findValidAt($municipality->id(), self::at('2026-04-15 00:00')), 'avant la révocation');
        self::assertCount(0, $repository->findValidAt($municipality->id(), self::at('2026-05-01 00:00')), 'révoqué, non expiré');

        $history = $repository->findByMunicipality($municipality->id());
        self::assertSame(['Plus tard', 'Renouvellement', 'Démo', 'Pilote'], array_map(static fn (MunicipalityCommercialAccess $a): string => $a->reason(), $history));
        self::assertNotNull($history[2]->revokedAt());
    }

    public function testChainProviderAndCreationPolicyEndToEnd(): void
    {
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $suspended = MunicipalityBuilder::aMunicipality()->withStatus(MunicipalityStatus::Suspended)->build();
        $without = MunicipalityBuilder::aMunicipality()->build();
        $cws = UserBuilder::aUser()->superAdmin()->build();
        $access = new MunicipalityCommercialAccess($municipality, $cws, self::at('2026-06-01 00:00'), null, 'Convention', self::at('2026-06-01 00:00'));
        $suspendedAccess = new MunicipalityCommercialAccess($suspended, $cws, self::at('2026-06-01 00:00'), null, 'Convention', self::at('2026-06-01 00:00'));
        $this->save($municipality, $suspended, $without, $cws, $access, $suspendedAccess);
        $now = self::at('2026-06-15 00:00');
        $policy = $this->service(EventCreationPolicy::class);

        $entitlements = $this->service(MunicipalityEntitlementProvider::class)->entitlementsFor($municipality, $now);
        self::assertCount(1, $entitlements);
        self::assertSame(EntitlementKind::CwsGrant, $entitlements[0]->kind);

        self::assertTrue($policy->canCreateEvent($municipality, $now), 'accès commercial sans abonnement payant');
        self::assertFalse($policy->canCreateEvent($without, $now), 'aucun droit');
        self::assertFalse($policy->canCreateEvent($suspended, $now), 'statut administratif distinct');

        $access->revoke($cws, self::at('2026-06-20 00:00'));
        $this->em->flush();
        self::assertTrue($policy->canCreateEvent($municipality, self::at('2026-06-19 00:00')));
        self::assertFalse($policy->canCreateEvent($municipality, self::at('2026-06-21 00:00')), 'révocation : plus de nouvelle création');
    }
}
