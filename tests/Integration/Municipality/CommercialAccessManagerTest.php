<?php

declare(strict_types=1);

namespace App\Tests\Integration\Municipality;

use App\Event\Application\EventPermission;
use App\Event\Application\EventAccessPolicy;
use App\Municipality\Application\CommercialAccessManager;
use App\Municipality\Application\EventCreationPolicy;
use App\Municipality\Domain\MunicipalityCommercialAccessRepository;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\EventFixtures;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\ServiceWorld;
use App\Tests\Support\UserBuilder;

/**
 * Accès commerciaux manuels (LOT 07.1) : attribution, renouvellement, révocation, audit et — surtout — continuité des événements
 * ouverts quand le droit de CRÉER de nouveaux événements disparaît (DM-073).
 */
final class CommercialAccessManagerTest extends DatabaseTestCase
{
    private CommercialAccessManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->service(CommercialAccessManager::class);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** @return list<string> */
    private function audited(): array
    {
        return array_map('strval', $this->em->getConnection()->fetchFirstColumn("SELECT action FROM audit_log WHERE action LIKE 'COMMERCIAL_ACCESS_%' ORDER BY occurred_at, id"));
    }

    public function testGrantMakesNewEventsPossibleAndIsAudited(): void
    {
        $cws = UserBuilder::aUser()->superAdmin()->build();
        $commune = MunicipalityBuilder::aMunicipality()->build();
        $this->save($cws, $commune);
        $policy = $this->service(EventCreationPolicy::class);
        self::assertFalse($policy->canCreateEvent($commune, $this->now()));

        $access = $this->manager->grant($commune, $this->now()->modify('+30 days'), 'Pilote 2026', $cws);

        self::assertTrue($policy->canCreateEvent($commune, $this->now()));
        self::assertSame('Pilote 2026', $access->reason());
        self::assertSame(['COMMERCIAL_ACCESS_GRANTED'], $this->audited());
        self::assertStringNotContainsString('Pilote 2026', (string) $this->em->getConnection()->fetchOne('SELECT new_value FROM audit_log WHERE action = ?', ['COMMERCIAL_ACCESS_GRANTED']), 'le motif n’entre pas dans l’audit (DT-037)');
    }

    public function testOnlyAnActiveSuperAdminMayWriteAndTheReasonIsMandatory(): void
    {
        $cws = UserBuilder::aUser()->superAdmin()->build();
        $agent = UserBuilder::aUser()->build();
        $commune = MunicipalityBuilder::aMunicipality()->build();
        $this->save($cws, $agent, $commune);

        try {
            $this->manager->grant($commune, null, 'x', $agent);
            self::fail('un utilisateur ordinaire ne peut pas attribuer');
        } catch (OperationNotAllowed) {
        }
        $this->expectException(DomainRuleViolation::class);
        $this->manager->grant($commune, null, '   ', $cws);
    }

    public function testASecondGrantWhileOneIsOpenIsRefusedAndRenewalChainsWithoutGapOrOverlap(): void
    {
        $cws = UserBuilder::aUser()->superAdmin()->build();
        $commune = MunicipalityBuilder::aMunicipality()->build();
        $this->save($cws, $commune);
        $first = $this->manager->grant($commune, $this->now()->modify('+10 days'), 'Pilote', $cws);

        try {
            $this->manager->grant($commune, null, 'Doublon', $cws);
            self::fail('attribution refusée tant qu’un accès est en cours');
        } catch (DomainRuleViolation) {
        }

        $second = $this->manager->renew($commune, $this->now()->modify('+40 days'), 'Renouvellement', $cws);

        self::assertEquals($first->expiresAt(), $second->grantedAt(), 'le successeur commence à l’échéance de l’accès en cours');
        $history = $this->service(MunicipalityCommercialAccessRepository::class)->findByMunicipality($commune->id());
        self::assertCount(2, $history, 'historique conservé');
        self::assertSame(['COMMERCIAL_ACCESS_GRANTED', 'COMMERCIAL_ACCESS_RENEWED'], $this->audited());
    }

    public function testRenewalRefusedWithoutOpenAccessOrWhenUnlimited(): void
    {
        $cws = UserBuilder::aUser()->superAdmin()->build();
        $commune = MunicipalityBuilder::aMunicipality()->build();
        $this->save($cws, $commune);
        try {
            $this->manager->renew($commune, null, 'x', $cws);
            self::fail('rien à renouveler');
        } catch (DomainRuleViolation) {
        }
        $this->manager->grant($commune, null, 'Illimité', $cws);
        $this->expectException(DomainRuleViolation::class);
        $this->manager->renew($commune, null, 'x', $cws);
    }

    public function testRevocationIsDefinitiveAuditedAndAllowsANewGrant(): void
    {
        $cws = UserBuilder::aUser()->superAdmin()->build();
        $commune = MunicipalityBuilder::aMunicipality()->build();
        $this->save($cws, $commune);
        $access = $this->manager->grant($commune, null, 'Pilote', $cws);
        $this->manager->revoke($access, $cws);

        self::assertFalse($this->service(EventCreationPolicy::class)->canCreateEvent($commune, $this->now()->modify('+1 second')));
        try {
            $this->manager->revoke($access, $cws);
            self::fail('déjà révoqué');
        } catch (DomainRuleViolation) {
        }
        $this->manager->grant($commune, null, 'Nouvel accès', $cws);
        self::assertTrue($this->service(EventCreationPolicy::class)->canCreateEvent($commune, $this->now()->modify('+1 second')));
        self::assertSame(['COMMERCIAL_ACCESS_GRANTED', 'COMMERCIAL_ACCESS_REVOKED', 'COMMERCIAL_ACCESS_GRANTED'], $this->audited());
    }

    public function testAnAuditFailureRollsTheGrantBack(): void
    {
        // La commune n'existe pas en base : le verrou échoue, rien n'est écrit.
        $cws = UserBuilder::aUser()->superAdmin()->build();
        $ghost = MunicipalityBuilder::aMunicipality()->build();
        $this->save($cws);
        try {
            $this->manager->grant($ghost, null, 'Fantôme', $cws);
            self::fail('commune inconnue');
        } catch (OperationNotAllowed) {
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM municipality_commercial_access'));
    }

    /**
     * Continuité (DM-073) : la révocation interdit les NOUVEAUX événements mais ne coupe rien de ce qui est ouvert.
     */
    public function testRevokingTheAccessNeverInterruptsAnOpenEvent(): void
    {
        $w = ServiceWorld::persist($this->em, self::getContainer());
        $organizer = $this->em->find($w->org::class, $w->org->id());
        $type = $this->em->getRepository(\App\Event\Domain\EventType::class)->findOneBy([]) ?? throw new \LogicException('Types initiaux absents.');
        $event = EventFixtures::draft($organizer, $this->em->find($w->adminO::class, $w->adminO->id()), $type);
        $event->activate(EventFixtures::now());
        $participation = EventFixtures::organizerParticipation($event);
        $this->save($event, $participation);
        $access = $this->service(MunicipalityCommercialAccessRepository::class)->findByMunicipality($organizer->id())[0];
        $cws = $this->em->find($w->superAdmin::class, $w->superAdmin->id());
        $admin = $this->em->find($w->adminO::class, $w->adminO->id());
        $policy = $this->service(EventAccessPolicy::class);
        self::assertTrue($policy->forNewEvent($admin, $organizer, $this->now())->isAllowed(), 'avant : création possible');

        $this->manager->revoke($access, $cws);
        $this->em->clear();
        $organizer = $this->em->find($w->org::class, $w->org->id());
        $admin = $this->em->find($w->adminO::class, $w->adminO->id());
        $event = $this->em->find($event::class, $event->id());
        $participation = $this->em->find($participation::class, $participation->id());

        self::assertFalse($policy->forNewEvent($admin, $organizer, $this->now())->isAllowed(), 'après : plus de NOUVEL événement');
        foreach ([EventPermission::ViewEvent, EventPermission::UpdateEvent, EventPermission::CloseEvent, EventPermission::InviteMunicipality] as $permission) {
            self::assertTrue($policy->forEvent($admin, $permission, $event)->isAllowed(), $permission->value.' reste possible sur l’événement ouvert');
        }
        self::assertTrue($policy->forParticipation($admin, EventPermission::CreateZone, $participation)->isAllowed(), 'les zones restent gérables');
    }
}
