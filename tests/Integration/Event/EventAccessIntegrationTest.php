<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Application\AccessDenialReason as Why;
use App\Event\Application\EventAccessPolicy;
use App\Event\Application\EventPermission as P;
use App\Event\Application\EventResourceResolver;
use App\Municipality\Domain\MembershipRepository;
use App\Municipality\Domain\MunicipalityCommercialAccess;
use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\MunicipalityStatus;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\EventScenario;
use App\Tests\Support\EventSchemaTestCase;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Politiques d'accès sur MySQL, avec les vrais repositories, la vraie chaîne de droits d'utilisation et le vrai
 * résolveur d'identifiants : aucune information ne vient du client, tout se déduit de la chaîne de rattachement.
 */
#[Group('requires-event-schema')]
final class EventAccessIntegrationTest extends EventSchemaTestCase
{
    private EventScenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = EventScenario::create();
        $this->save(...$this->s->entities);
    }

    private function policy(): EventAccessPolicy
    {
        return $this->service(EventAccessPolicy::class);
    }

    private function resolver(): EventResourceResolver
    {
        return $this->service(EventResourceResolver::class);
    }

    private static function at(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    private function grantCommercialAccess(?\DateTimeImmutable $expiresAt = null): MunicipalityCommercialAccess
    {
        $access = new MunicipalityCommercialAccess($this->s->a, $this->s->superAdmin, self::at('2026-01-01 00:00'), $expiresAt, 'Convention CWS', self::at('2026-01-01 00:00'));
        $this->save($access);

        return $access;
    }

    // ── Résolution d'identifiants non fiables ────────────────────────────────

    public function testAnAuthorizedUserResolvesItsOwnResources(): void
    {
        $s = $this->s;
        $r = $this->resolver();

        self::assertSame($s->event->id()->toRfc4122(), $r->event($s->adminA, $s->event->id()->toRfc4122(), P::UpdateEvent)->id()->toRfc4122());
        self::assertSame($s->zoneB->id()->toRfc4122(), $r->zone($s->adminB, $s->zoneB->id()->toRfc4122(), P::ModifyZone)->id()->toRfc4122());
        self::assertSame($s->slotA->id()->toRfc4122(), $r->slot($s->adminA, $s->slotA->id()->toRfc4122(), P::DeleteZoneValiditySlot)->id()->toRfc4122());
        self::assertSame($s->participationB->id()->toRfc4122(), $r->participation($s->adminB, $s->participationB->id()->toRfc4122(), P::SuspendParticipation)->id()->toRfc4122());
    }

    public function testAForeignUuidGivesNoRightAndLooksLikeAMissingResource(): void
    {
        $s = $this->s;
        $r = $this->resolver();
        $missing = Uuid::v7()->toRfc4122();
        $attempts = [
            'événement étranger' => fn () => $r->event($s->adminC, $s->event->id()->toRfc4122(), P::ViewEvent),
            'participation étrangère' => fn () => $r->participation($s->adminC, $s->participationB->id()->toRfc4122(), P::SuspendParticipation),
            'zone étrangère' => fn () => $r->zone($s->adminC, $s->zoneA->id()->toRfc4122(), P::ModifyZone),
            'zone de A visée par B' => fn () => $r->zone($s->adminB, $s->zoneA->id()->toRfc4122(), P::ModifyZone),
            'zone de B visée par A' => fn () => $r->zone($s->adminA, $s->zoneB->id()->toRfc4122(), P::DeleteZone),
            'créneau étranger' => fn () => $r->slot($s->adminC, $s->slotA->id()->toRfc4122(), P::DeleteZoneValiditySlot),
            'créneau de A visé par B' => fn () => $r->slot($s->adminB, $s->slotA->id()->toRfc4122(), P::DeleteZoneValiditySlot),
            'invitation de B visée par A' => fn () => $r->participation($s->adminA, $s->participationB->id()->toRfc4122(), P::AcceptInvitation),
            'zone inexistante' => fn () => $r->zone($s->adminA, $missing, P::ModifyZone),
            'événement inexistant' => fn () => $r->event($s->adminA, $missing, P::ViewEvent),
            'créneau inexistant' => fn () => $r->slot($s->adminA, $missing, P::DeleteZoneValiditySlot),
            'identifiant invalide' => fn () => $r->zone($s->adminA, "1' OR 1=1 --", P::ModifyZone),
            'super-admin CWS' => fn () => $r->zone($s->superAdmin, $s->zoneA->id()->toRfc4122(), P::ModifyZone),
        ];

        foreach ($attempts as $label => $attempt) {
            try {
                $attempt();
                self::fail($label.' : l\'accès aurait dû être refusé.');
            } catch (OperationNotAllowed $e) {
                self::assertSame('Ressource inaccessible.', $e->getMessage(), $label.' : même message que pour une ressource inexistante');
            }
        }
    }

    public function testAnAuthorizedUserStillGetsSpecificStateRefusals(): void
    {
        $s = $this->s;
        $s->slotA->markDeleted(F::now());
        $this->em->flush();

        $this->expectException(OperationNotAllowed::class);
        $this->expectExceptionMessage('Ce créneau est supprimé.');
        $this->resolver()->slot($s->adminA, $s->slotA->id()->toRfc4122(), P::DeleteZoneValiditySlot);
    }

    public function testRolesDoNotLeakAcrossMunicipalities(): void
    {
        // Administrateur de C, mais simple instructeur de A : sur l'événement de A, seul son rôle dans A compte.
        $s = $this->s;
        $dual = UserBuilder::aUser()->build();
        $this->save($dual, MembershipBuilder::aMembership($dual, $s->c)->withRoles(MunicipalityRole::MunicipalityAdmin)->build(), MembershipBuilder::aMembership($dual, $s->a)->withRoles(MunicipalityRole::Instructor)->build());
        $this->em->clear();
        $event = $this->em->find($s->event::class, $s->event->id()) ?? self::fail('Événement absent.');
        $zone = $this->em->find($s->zoneA::class, $s->zoneA->id()) ?? self::fail('Zone absente.');

        self::assertSame(Why::RoleNotAllowed, $this->policy()->forEvent($dual, P::UpdateEvent, $event)->reason);
        self::assertSame(Why::RoleNotAllowed, $this->policy()->forZone($dual, P::ModifyZone, $zone)->reason);
        self::assertTrue($this->policy()->forEvent($dual, P::ViewEvent, $event)->isAllowed());
        self::assertCount(2, $this->service(MembershipRepository::class)->findActiveByUser($dual));
    }

    public function testActiveMembershipsListExcludesInactiveOnes(): void
    {
        $s = $this->s;
        $user = UserBuilder::aUser()->build();
        $this->save($user, MembershipBuilder::aMembership($user, $s->a)->withRoles(MunicipalityRole::MunicipalityAdmin)->build(), MembershipBuilder::aMembership($user, $s->b)->withRoles(MunicipalityRole::MunicipalityAdmin)->inactive()->build());

        $memberships = $this->service(MembershipRepository::class)->findActiveByUser($user);

        self::assertCount(1, $memberships);
        self::assertSame($s->a->id()->toRfc4122(), $memberships[0]->municipality()->id()->toRfc4122());
    }

    // ── Visibilité avec les vrais dépôts ─────────────────────────────────────

    public function testVisibilityWithRealRepositories(): void
    {
        $s = $this->s;
        $this->em->clear();
        $event = $this->em->find($s->event::class, $s->event->id()) ?? self::fail('Événement absent.');
        $zoneA = $this->em->find($s->zoneA::class, $s->zoneA->id()) ?? self::fail('Zone absente.');
        $policy = $this->policy();

        foreach ([$s->adminA, $s->instructorA, $s->adminB, $s->adminD] as $user) {
            self::assertTrue($policy->forEvent($user, P::ViewEvent, $event)->isAllowed());
        }
        self::assertSame(Why::NoAccess, $policy->forEvent($s->adminC, P::ViewEvent, $event)->reason);
        self::assertSame(Why::NoAccess, $policy->forEvent($s->superAdmin, P::ViewEvent, $event)->reason);
        self::assertTrue($policy->forZone($s->adminB, P::ViewZone, $zoneA)->isAllowed(), 'participante : lecture seule');
        self::assertSame(Why::NoAccess, $policy->forZone($s->adminD, P::ViewZone, $zoneA)->reason, 'invitée seulement');
        self::assertSame(Why::NoAccess, $policy->forZone($s->adminB, P::ModifyZone, $zoneA)->reason);
    }

    // ── Droits d'utilisation avec la vraie chaîne ────────────────────────────

    public function testCreationWithoutAnyUsageRightIsRefused(): void
    {
        $s = $this->s;

        self::assertSame(Why::NoUsageRight, $this->policy()->forNewEvent($s->adminA, $s->a, self::at('2026-06-15 10:00'))->reason);
    }

    public function testValidCommercialAccessAllowsCreationWithoutAPaidSubscription(): void
    {
        $this->grantCommercialAccess();
        $s = $this->s;

        self::assertTrue($this->policy()->forNewEvent($s->adminA, $s->a, self::at('2026-06-15 10:00'))->isAllowed());
        self::assertTrue($this->policy()->forNewEvent($s->managerA, $s->a, self::at('2026-06-15 10:00'))->isAllowed());
        self::assertSame(Why::RoleNotAllowed, $this->policy()->forNewEvent($s->instructorA, $s->a, self::at('2026-06-15 10:00'))->reason);
    }

    public function testAnAccessGrantedToAnotherMunicipalityGivesNoRight(): void
    {
        $this->grantCommercialAccess();
        $s = $this->s;

        self::assertSame(Why::NoUsageRight, $this->policy()->forNewEvent($s->adminB, $s->b, self::at('2026-06-15 10:00'))->reason);
        self::assertSame(Why::NoAccess, $this->policy()->forNewEvent($s->adminB, $s->a, self::at('2026-06-15 10:00'))->reason);
    }

    public function testExpiredAccessBlocksCreationButNotTheManagementOfExistingEvents(): void
    {
        $this->grantCommercialAccess(self::at('2026-06-10 00:00'));
        $s = $this->s;
        $policy = $this->policy();

        self::assertTrue($policy->forNewEvent($s->adminA, $s->a, self::at('2026-06-09 00:00'))->isAllowed(), 'avant expiration');
        self::assertSame(Why::NoUsageRight, $policy->forNewEvent($s->adminA, $s->a, self::at('2026-06-10 00:00'))->reason, 'à l\'expiration');
        $this->assertExistingEventStillManageable();
    }

    public function testRevokedAccessBlocksCreationButNotTheManagementOfExistingEvents(): void
    {
        $access = $this->grantCommercialAccess();
        $s = $this->s;
        self::assertTrue($this->policy()->forNewEvent($s->adminA, $s->a, self::at('2026-06-15 10:00'))->isAllowed());

        $access->revoke($s->superAdmin, self::at('2026-06-12 00:00'));
        $this->em->flush();

        self::assertSame(Why::NoUsageRight, $this->policy()->forNewEvent($s->adminA, $s->a, self::at('2026-06-15 10:00'))->reason);
        $this->assertExistingEventStillManageable();
    }

    public function testAdministrativelyInactiveOrSuspendedMunicipalityCannotCreateDespiteAValidAccess(): void
    {
        $this->grantCommercialAccess();
        $s = $this->s;

        $s->a->changeStatus(MunicipalityStatus::Suspended, F::now());
        $this->em->flush();
        self::assertSame(Why::MunicipalityNotWritable, $this->policy()->forNewEvent($s->adminA, $s->a, self::at('2026-06-15 10:00'))->reason);

        $s->a->changeStatus(MunicipalityStatus::Inactive, F::now());
        $this->em->flush();
        self::assertSame(Why::NoAccess, $this->policy()->forNewEvent($s->adminA, $s->a, self::at('2026-06-15 10:00'))->reason);
    }

    private function assertExistingEventStillManageable(): void
    {
        $s = $this->s;
        $policy = $this->policy();
        $event = $s->event;

        foreach ([P::UpdateEvent, P::SuspendEvent, P::CloseEvent, P::InviteMunicipality] as $permission) {
            self::assertTrue($policy->forEvent($s->adminA, $permission, $event)->isAllowed(), $permission->value);
        }
        self::assertTrue($policy->forZone($s->adminA, P::ModifyZone, $s->zoneA)->isAllowed());
        self::assertTrue($policy->forSlot($s->adminA, P::DeleteZoneValiditySlot, $s->slotA)->isAllowed());
    }
}
