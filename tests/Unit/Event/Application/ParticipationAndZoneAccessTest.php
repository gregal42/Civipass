<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event\Application;

use App\Event\Application\AccessDecision;
use App\Event\Application\AccessDenialReason as Why;
use App\Event\Application\EventPermission as P;
use App\Event\Domain\EventZoneValidity;
use App\Event\Domain\Validity\ValiditySlot;
use App\Event\Domain\ZoneType;
use App\Municipality\Domain\MunicipalityStatus;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\EventScenario;
use App\Tests\Support\TimeFactory as T;
use PHPUnit\Framework\TestCase;

/**
 * Isolation intercommunale : A organise, B est invitée (puis acceptante), C est étrangère, D est une autre invitée.
 */
final class ParticipationAndZoneAccessTest extends TestCase
{
    private static function denied(AccessDecision $decision, Why $reason, string $message = ''): void
    {
        self::assertFalse($decision->isAllowed(), $message);
        self::assertSame($reason, $decision->reason, $message);
    }

    // ── Invitation ───────────────────────────────────────────────────────────

    public function testAnInvitationCanOnlyBeAcceptedByItsRecipientAdministrator(): void
    {
        $s = EventScenario::create(bAccepted: false);
        $policy = $s->policy();

        self::assertTrue($policy->forParticipation($s->adminB, P::AcceptInvitation, $s->participationB)->isAllowed());
        self::denied($policy->forParticipation($s->managerB, P::AcceptInvitation, $s->participationB), Why::RoleNotAllowed, 'R2 : le gestionnaire ne peut pas accepter');
        self::denied($policy->forParticipation($s->instructorB, P::AcceptInvitation, $s->participationB), Why::RoleNotAllowed);
        self::denied($policy->forParticipation($s->adminA, P::AcceptInvitation, $s->participationB), Why::NoAccess, 'l\'organisatrice n\'accepte pas pour B');
        self::denied($policy->forParticipation($s->adminC, P::AcceptInvitation, $s->participationB), Why::NoAccess);
        self::denied($policy->forParticipation($s->adminD, P::AcceptInvitation, $s->participationB), Why::NoAccess, 'une autre invitée non plus');
        self::denied($policy->forParticipation($s->superAdmin, P::AcceptInvitation, $s->participationB), Why::NoAccess);
    }

    public function testAcceptanceStateRules(): void
    {
        $s = EventScenario::create();
        $policy = $s->policy();

        self::denied($policy->forParticipation($s->adminB, P::AcceptInvitation, $s->participationB), Why::InvalidParticipationState, 'déjà acceptée');

        $pending = EventScenario::create(bAccepted: false);
        $pending->event->close(F::now());
        self::denied($pending->policy()->forParticipation($pending->adminB, P::AcceptInvitation, $pending->participationB), Why::EventClosed);

        $suspended = EventScenario::create(bAccepted: false);
        $suspended->b->changeStatus(MunicipalityStatus::Suspended, F::now());
        self::denied($suspended->policy()->forParticipation($suspended->adminB, P::AcceptInvitation, $suspended->participationB), Why::MunicipalityNotWritable);
    }

    // ── Participation propre ─────────────────────────────────────────────────

    public function testEachMunicipalityManagesOnlyItsOwnParticipation(): void
    {
        $s = EventScenario::create();
        $policy = $s->policy();

        foreach ([P::SuspendParticipation, P::CloseParticipation] as $permission) {
            self::assertTrue($policy->forParticipation($s->adminB, $permission, $s->participationB)->isAllowed(), $permission->value.' administrateur B');
            self::assertTrue($policy->forParticipation($s->managerB, $permission, $s->participationB)->isAllowed(), $permission->value.' gestionnaire B');
            self::denied($policy->forParticipation($s->instructorB, $permission, $s->participationB), Why::RoleNotAllowed);
            self::denied($policy->forParticipation($s->adminA, $permission, $s->participationB), Why::NoAccess, $permission->value.' : A ne touche pas la participation de B');
            self::denied($policy->forParticipation($s->managerA, $permission, $s->participationB), Why::NoAccess);
            self::denied($policy->forParticipation($s->adminC, $permission, $s->participationB), Why::NoAccess);
            self::denied($policy->forParticipation($s->adminB, $permission, $s->participationA), Why::NoAccess, $permission->value.' : B ne touche pas la participation de A');
        }
    }

    public function testParticipationStateRules(): void
    {
        $s = EventScenario::create();
        $policy = $s->policy();

        self::denied($policy->forParticipation($s->adminB, P::ReactivateParticipation, $s->participationB), Why::InvalidParticipationState, 'active : rien à réactiver');
        $s->participationB->suspend('Motif', F::now());
        self::assertTrue($policy->forParticipation($s->adminB, P::ReactivateParticipation, $s->participationB)->isAllowed());
        self::denied($policy->forParticipation($s->adminB, P::SuspendParticipation, $s->participationB), Why::InvalidParticipationState);
        self::assertTrue($policy->forParticipation($s->adminB, P::CloseParticipation, $s->participationB)->isAllowed(), 'une participation suspendue peut être clôturée');

        $s->participationB->close(F::now());
        foreach ([P::SuspendParticipation, P::ReactivateParticipation, P::CloseParticipation, P::AcceptInvitation] as $permission) {
            self::denied($policy->forParticipation($s->adminB, $permission, $s->participationB), Why::InvalidParticipationState, $permission->value.' sur participation clôturée');
        }
    }

    public function testReactivationIsRefusedOnceTheEventIsClosed(): void
    {
        $s = EventScenario::create();
        $s->participationB->suspend('Motif', F::now());
        $s->event->close(F::now());

        self::denied($s->policy()->forParticipation($s->adminB, P::ReactivateParticipation, $s->participationB), Why::EventClosed);
    }

    public function testOrganizerParticipationAndInvitationsAreNotClosedOnTheirOwn(): void
    {
        $s = EventScenario::create(bAccepted: false);
        $policy = $s->policy();

        self::denied($policy->forParticipation($s->adminA, P::CloseParticipation, $s->participationA), Why::OrganizerParticipationBoundToEvent);
        self::denied($policy->forParticipation($s->adminB, P::CloseParticipation, $s->participationB), Why::InvalidParticipationState, 'invitation : une invitation ne se décline pas');
        self::denied($policy->forParticipation($s->adminB, P::SuspendParticipation, $s->participationB), Why::InvalidParticipationState);
    }

    public function testParticipationsAreVisibleToTheirMunicipalityAndToTheOrganizer(): void
    {
        $s = EventScenario::create();
        $policy = $s->policy();

        foreach ([$s->participationA, $s->participationB, $s->participationD] as $participation) {
            self::assertTrue($policy->forParticipation($s->adminA, P::ViewParticipation, $participation)->isAllowed(), 'l\'organisatrice voit toutes les participations');
            self::assertTrue($policy->forParticipation($s->instructorA, P::ViewParticipation, $participation)->isAllowed());
        }
        self::assertTrue($policy->forParticipation($s->instructorB, P::ViewParticipation, $s->participationB)->isAllowed());
        self::denied($policy->forParticipation($s->adminB, P::ViewParticipation, $s->participationD), Why::NoAccess, 'B ne voit pas la participation de D');
        self::denied($policy->forParticipation($s->adminB, P::ViewParticipation, $s->participationA), Why::NoAccess);
        self::denied($policy->forParticipation($s->adminC, P::ViewParticipation, $s->participationB), Why::NoAccess);
        self::denied($policy->forParticipation($s->superAdmin, P::ViewParticipation, $s->participationB), Why::NoAccess);
    }

    // ── Zones ────────────────────────────────────────────────────────────────

    public function testZoneCreationIsReservedToTheAdministratorOfTheOwningMunicipality(): void
    {
        $s = EventScenario::create();
        $policy = $s->policy();

        self::assertTrue($policy->forParticipation($s->adminA, P::CreateZone, $s->participationA)->isAllowed());
        self::assertTrue($policy->forParticipation($s->adminB, P::CreateZone, $s->participationB)->isAllowed());
        self::denied($policy->forParticipation($s->managerA, P::CreateZone, $s->participationA), Why::RoleNotAllowed, 'DM-057 : le gestionnaire n\'administre pas les zones');
        self::denied($policy->forParticipation($s->instructorA, P::CreateZone, $s->participationA), Why::RoleNotAllowed);
        self::denied($policy->forParticipation($s->adminB, P::CreateZone, $s->participationA), Why::NoAccess, 'B ne crée pas dans la participation de A');
        self::denied($policy->forParticipation($s->adminA, P::CreateZone, $s->participationB), Why::NoAccess, 'A ne crée pas dans la participation de B');
        self::denied($policy->forParticipation($s->adminC, P::CreateZone, $s->participationA), Why::NoAccess);
    }

    public function testZoneEditionDependsOnTheParticipationState(): void
    {
        $pending = EventScenario::create(bAccepted: false);
        self::denied($pending->policy()->forParticipation($pending->adminB, P::CreateZone, $pending->participationB), Why::ParticipationNotEditable, 'INVITED');
        self::denied($pending->policy()->forZone($pending->adminB, P::ModifyZone, $pending->zoneB), Why::ParticipationNotEditable, 'la zone proposée ne se modifie pas avant l\'acceptation');
        self::assertTrue($pending->policy()->forZone($pending->adminB, P::ViewZone, $pending->zoneB)->isAllowed(), 'mais B voit sa zone proposée');

        $suspended = EventScenario::create();
        $suspended->participationB->suspend('Motif', F::now());
        foreach ([P::ModifyZone, P::DeleteZone, P::ConfigureZoneValidity] as $permission) {
            self::assertTrue($suspended->policy()->forZone($suspended->adminB, $permission, $suspended->zoneB)->isAllowed(), $permission->value.' : participation SUSPENDED (R6)');
        }
        self::assertTrue($suspended->policy()->forParticipation($suspended->adminB, P::CreateZone, $suspended->participationB)->isAllowed());

        $closed = EventScenario::create();
        $closed->participationB->close(F::now());
        self::denied($closed->policy()->forZone($closed->adminB, P::ModifyZone, $closed->zoneB), Why::ParticipationNotEditable);
        self::denied($closed->policy()->forParticipation($closed->adminB, P::CreateZone, $closed->participationB), Why::ParticipationNotEditable);
    }

    public function testClosedEventFreezesZonesAndSlots(): void
    {
        $s = EventScenario::create();
        $s->event->close(F::now());
        $policy = $s->policy();

        self::denied($policy->forZone($s->adminA, P::ModifyZone, $s->zoneA), Why::EventClosed);
        self::denied($policy->forZone($s->adminA, P::ConfigureZoneValidity, $s->zoneA), Why::EventClosed);
        self::denied($policy->forParticipation($s->adminA, P::CreateZone, $s->participationA), Why::EventClosed);
        self::denied($policy->forSlot($s->adminA, P::DeleteZoneValiditySlot, $s->slotA), Why::EventClosed);
    }

    public function testNoMunicipalityEditsTheZonesOfAnother(): void
    {
        $s = EventScenario::create();
        $policy = $s->policy();

        foreach ([P::ModifyZone, P::DeleteZone, P::ConfigureZoneValidity] as $permission) {
            self::assertTrue($policy->forZone($s->adminA, $permission, $s->zoneA)->isAllowed(), $permission->value.' A sur sa zone');
            self::assertTrue($policy->forZone($s->adminB, $permission, $s->zoneB)->isAllowed(), $permission->value.' B sur sa zone');
            self::denied($policy->forZone($s->adminB, $permission, $s->zoneA), Why::NoAccess, $permission->value.' : B sur la zone de A');
            self::denied($policy->forZone($s->adminA, $permission, $s->zoneB), Why::NoAccess, $permission->value.' : A sur la zone de B');
            self::denied($policy->forZone($s->adminC, $permission, $s->zoneA), Why::NoAccess, $permission->value.' : C');
            self::denied($policy->forZone($s->adminD, $permission, $s->zoneA), Why::NoAccess);
            self::denied($policy->forZone($s->managerA, $permission, $s->zoneA), Why::RoleNotAllowed, $permission->value.' : gestionnaire');
            self::denied($policy->forZone($s->instructorA, $permission, $s->zoneA), Why::RoleNotAllowed);
            self::denied($policy->forZone($s->superAdmin, $permission, $s->zoneA), Why::NoAccess);
        }
    }

    public function testADeletedZoneIsFrozenForItsOwnersAndInvisibleToOthers(): void
    {
        $s = EventScenario::create();
        $s->zoneA->markDeleted(F::now());
        $policy = $s->policy();

        foreach ([P::ModifyZone, P::DeleteZone, P::ConfigureZoneValidity, P::ViewZone] as $permission) {
            self::denied($policy->forZone($s->adminA, $permission, $s->zoneA), Why::ZoneDeleted, $permission->value);
            self::denied($policy->forZone($s->adminC, $permission, $s->zoneA), Why::NoAccess, 'un étranger ne doit pas apprendre que la zone est supprimée');
        }
        self::denied($policy->forSlot($s->adminA, P::DeleteZoneValiditySlot, $s->slotA), Why::ZoneDeleted);
    }

    public function testZonesAreReadOnlyForParticipatingMunicipalitiesNeverForInvitedOnes(): void
    {
        $s = EventScenario::create();
        $policy = $s->policy();

        self::assertTrue($policy->forZone($s->adminB, P::ViewZone, $s->zoneA)->isAllowed(), 'DM-007 : zones des autres communes en lecture seule');
        self::assertTrue($policy->forZone($s->instructorB, P::ViewZone, $s->zoneA)->isAllowed());
        self::assertTrue($policy->forZone($s->adminA, P::ViewZone, $s->zoneB)->isAllowed());
        self::denied($policy->forZone($s->adminD, P::ViewZone, $s->zoneA), Why::NoAccess, 'D est seulement invitée : aucune lecture des zones d\'autrui');
        self::denied($policy->forZone($s->adminC, P::ViewZone, $s->zoneA), Why::NoAccess);
        self::denied($policy->forZone($s->superAdmin, P::ViewZone, $s->zoneA), Why::NoAccess);
    }

    // ── Créneaux ─────────────────────────────────────────────────────────────

    public function testSlotsFollowTheChainSlotZoneParticipationMunicipality(): void
    {
        $s = EventScenario::create();
        $policy = $s->policy();

        self::assertTrue($policy->forSlot($s->adminA, P::DeleteZoneValiditySlot, $s->slotA)->isAllowed());
        self::assertTrue($policy->forSlot($s->adminB, P::DeleteZoneValiditySlot, $s->slotB)->isAllowed());
        self::denied($policy->forSlot($s->adminB, P::DeleteZoneValiditySlot, $s->slotA), Why::NoAccess, 'un UUID de créneau étranger ne donne aucun droit');
        self::denied($policy->forSlot($s->adminA, P::DeleteZoneValiditySlot, $s->slotB), Why::NoAccess);
        self::denied($policy->forSlot($s->adminC, P::DeleteZoneValiditySlot, $s->slotA), Why::NoAccess);
        self::denied($policy->forSlot($s->managerA, P::DeleteZoneValiditySlot, $s->slotA), Why::RoleNotAllowed);
        self::denied($policy->forSlot($s->instructorA, P::DeleteZoneValiditySlot, $s->slotA), Why::RoleNotAllowed);
        self::denied($policy->forSlot($s->superAdmin, P::DeleteZoneValiditySlot, $s->slotA), Why::NoAccess);
    }

    public function testADeletedSlotNeverBecomesEditableAgain(): void
    {
        $s = EventScenario::create();
        $s->slotA->markDeleted(F::now());
        $policy = $s->policy();

        self::denied($policy->forSlot($s->adminA, P::DeleteZoneValiditySlot, $s->slotA), Why::SlotDeleted);
        self::denied($policy->forSlot($s->adminC, P::DeleteZoneValiditySlot, $s->slotA), Why::NoAccess, 'aucune fuite vers les étrangers');
        // Le remplacement passe par un nouveau créneau, jamais par la réactivation de l'ancien.
        $replacement = EventZoneValidity::fromSlot($s->zoneA, ValiditySlot::punctual(T::date('2026-06-14'), T::time('10:00'), T::time('11:00')), F::now());
        self::assertTrue($policy->forSlot($s->adminA, P::DeleteZoneValiditySlot, $replacement)->isAllowed());
    }

    public function testSlotOfASuspendedMunicipalityIsReadOnly(): void
    {
        $s = EventScenario::create();
        $s->b->changeStatus(MunicipalityStatus::Suspended, F::now());

        self::denied($s->policy()->forSlot($s->adminB, P::DeleteZoneValiditySlot, $s->slotB), Why::MunicipalityNotWritable);
        self::denied($s->policy()->forZone($s->adminB, P::ModifyZone, $s->zoneB), Why::MunicipalityNotWritable);
    }

    public function testAZoneCreatedByAnotherAdministratorOfTheSameMunicipalityIsStillManageable(): void
    {
        // Le contrôle porte sur la commune de la participation, pas sur le créateur de la zone.
        $s = EventScenario::create();
        $zone = \App\Event\Domain\EventZone::create($s->participationA, 'Autre zone', ZoneType::District, null, $s->managerA, F::now());

        self::assertTrue($s->policy()->forZone($s->adminA, P::ModifyZone, $zone)->isAllowed());
        self::denied($s->policy()->forZone($s->adminB, P::ModifyZone, $zone), Why::NoAccess);
    }
}
