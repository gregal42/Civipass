<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Event\Domain\EventZone;
use App\Event\Domain\Validity\ValidityMode;
use App\Event\Domain\ZoneStatus;
use App\Event\Domain\ZoneType;
use App\Shared\Domain\DomainRuleViolation;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\TestCase;

final class EventZoneTest extends TestCase
{
    public function testManualZoneIsConfirmedAtCreation(): void
    {
        $participation = F::organizerParticipation(F::draft());
        $creator = UserBuilder::aUser()->build();

        $zone = EventZone::create($participation, '  Route départementale 107  ', ZoneType::Street, '  Axe principal ', $creator, F::now());

        self::assertSame('Route départementale 107', $zone->label());
        self::assertSame('Axe principal', $zone->description());
        self::assertSame(ZoneType::Street, $zone->type());
        self::assertSame(ZoneStatus::Active, $zone->status());
        self::assertTrue($zone->isConfirmed());
        self::assertEquals(F::now(), $zone->confirmedAt());
        self::assertSame(ValidityMode::Unconfigured, $zone->validityMode());
        self::assertFalse($zone->isDeleted());
        self::assertSame($participation, $zone->participation());
        self::assertSame($creator, $zone->createdBy());
    }

    public function testManualWholeMunicipalityNeedsExplicitConfirmation(): void
    {
        $participation = F::organizerParticipation(F::draft());
        $creator = UserBuilder::aUser()->build();

        $zone = EventZone::create($participation, 'Commune entière', ZoneType::WholeMunicipality, null, $creator, F::now(), true);
        self::assertTrue($zone->isConfirmed());

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('confirmé explicitement');
        EventZone::create($participation, 'Commune entière', ZoneType::WholeMunicipality, null, $creator, F::now());
    }

    public function testAutomaticProposalIsNotConfirmedAndGrantsNothing(): void
    {
        $event = F::active();
        $participation = F::organizerParticipation($event);

        $zone = EventZone::proposeWholeMunicipality($participation, UserBuilder::aUser()->build(), F::now());

        self::assertSame(sprintf('Commune entière — %s', $participation->municipality()->name()), $zone->label());
        self::assertSame(ZoneType::WholeMunicipality, $zone->type());
        self::assertSame(ZoneStatus::Active, $zone->status());
        self::assertFalse($zone->isConfirmed());
        self::assertNull($zone->confirmedAt());
        self::assertSame(ValidityMode::Unconfigured, $zone->validityMode());
        self::assertFalse($zone->satisfiesActivationRequirement());
        self::assertFalse($zone->mayOpenRights());
    }

    public function testNoZoneStatusMeaningToConfirm(): void
    {
        self::assertSame(['ACTIVE', 'SUSPENDED'], array_map(static fn (ZoneStatus $s): string => $s->value, ZoneStatus::cases()));
    }

    public function testProposalIsPossibleForAnInvitedParticipationButTheZoneCannotBeEditedYet(): void
    {
        $event = F::draft();
        $invited = F::invited($event);

        $zone = EventZone::proposeWholeMunicipality($invited, UserBuilder::aUser()->build(), F::now());

        self::assertFalse($zone->isConfirmed());
        foreach ([
            fn () => $zone->confirm(F::now()),
            fn () => $zone->update('Autre', null, F::now()),
            fn () => $zone->suspend(F::now()),
            fn () => $zone->changeValidityMode(ValidityMode::EventDuration, F::now()),
            fn () => $zone->markDeleted(F::now()),
            fn () => F::zone($invited),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Une commune invitée ne peut pas modifier ses zones.');
            } catch (DomainRuleViolation) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAcceptedCommuneCanConfirmAndConfigureTheProposedZone(): void
    {
        $event = F::draft();
        $invited = F::invited($event);
        $zone = EventZone::proposeWholeMunicipality($invited, UserBuilder::aUser()->build(), F::now());
        $invited->accept(F::now()->modify('+1 day'));
        $later = F::now()->modify('+2 days');

        $zone->confirm($later);
        $zone->changeValidityMode(ValidityMode::EventDuration, $later);

        self::assertEquals($later, $zone->confirmedAt());
        self::assertSame(ValidityMode::EventDuration, $zone->validityMode());
        self::assertTrue($zone->satisfiesActivationRequirement());
    }

    public function testConfirmationIsIdempotent(): void
    {
        $participation = F::organizerParticipation(F::draft());
        $zone = EventZone::proposeWholeMunicipality($participation, UserBuilder::aUser()->build(), F::now());

        $zone->confirm(F::now()->modify('+1 hour'));
        $zone->confirm(F::now()->modify('+5 hours'));

        self::assertEquals(F::now()->modify('+1 hour'), $zone->confirmedAt());
    }

    public function testAutomaticProposalIsRefusedOnAClosedParticipation(): void
    {
        $event = F::draft();
        $organizer = F::organizerParticipation($event);
        $event->close(F::now());
        $organizer->closeWithEvent(F::now());

        $this->expectException(DomainRuleViolation::class);
        EventZone::proposeWholeMunicipality($organizer, UserBuilder::aUser()->build(), F::now());
    }

    public function testSuspendedParticipationKeepsZonesEditableButNeverOperational(): void
    {
        $event = F::active();
        $participation = F::accepted($event);
        $zone = F::readyZone($participation);
        $participation->suspend('Motif', F::now());
        $later = F::now()->modify('+1 day');

        $zone->update('Nouveau libellé', 'Description', $later);
        $zone->confirm($later);
        $zone->suspend($later);
        $zone->reactivate($later);
        $zone->changeValidityMode(ValidityMode::SpecificPeriods, $later);
        $created = EventZone::create($participation, 'Autre zone', ZoneType::District, null, UserBuilder::aUser()->build(), $later);

        self::assertSame('Nouveau libellé', $zone->label());
        self::assertSame(ValidityMode::SpecificPeriods, $zone->validityMode());
        self::assertTrue($created->isConfirmed());
        self::assertFalse($zone->mayOpenRights());
        self::assertFalse($zone->satisfiesActivationRequirement(), 'une participation suspendue ne satisfait pas l\'activation (R7)');
    }

    public function testClosedParticipationFreezesItsZones(): void
    {
        $participation = F::accepted(F::active());
        $zone = F::readyZone($participation);
        $participation->close(F::now());

        foreach ([
            fn () => $zone->update('Autre', null, F::now()),
            fn () => $zone->confirm(F::now()),
            fn () => $zone->markDeleted(F::now()),
            fn () => F::zone($participation),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Participation clôturée : zones figées.');
            } catch (DomainRuleViolation) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testZoneSuspensionAndReactivation(): void
    {
        $zone = F::readyZone(F::organizerParticipation(F::draft()));

        $zone->suspend(F::now());
        self::assertSame(ZoneStatus::Suspended, $zone->status());
        self::assertFalse($zone->satisfiesActivationRequirement());

        $zone->reactivate(F::now());
        self::assertSame(ZoneStatus::Active, $zone->status());
        self::assertTrue($zone->satisfiesActivationRequirement());

        $this->expectException(DomainRuleViolation::class);
        $zone->reactivate(F::now());
    }

    public function testLogicalDeletionIsDefinitiveAndDistinctFromSuspension(): void
    {
        $zone = F::readyZone(F::organizerParticipation(F::draft()));

        $zone->markDeleted(F::now());

        self::assertTrue($zone->isDeleted());
        self::assertSame(ZoneStatus::Active, $zone->status(), 'la suppression ne change pas le statut');
        self::assertFalse($zone->satisfiesActivationRequirement());
        foreach ([
            fn () => $zone->update('Autre', null, F::now()),
            fn () => $zone->confirm(F::now()),
            fn () => $zone->suspend(F::now()),
            fn () => $zone->reactivate(F::now()),
            fn () => $zone->changeValidityMode(ValidityMode::EventDuration, F::now()),
            fn () => $zone->markDeleted(F::now()),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Une zone supprimée n\'est plus modifiable.');
            } catch (DomainRuleViolation) {
                self::addToAssertionCount(1);
            }
        }
        foreach ((new \ReflectionClass(EventZone::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertDoesNotMatchRegularExpression('/^(restore|undelete|recover|unmark)/i', $method->getName(), 'aucun rétablissement implicite');
        }
    }

    public function testActivationRequirementNeedsEveryCondition(): void
    {
        $event = F::draft();
        $participation = F::organizerParticipation($event);
        $zone = F::zone($participation);
        self::assertFalse($zone->satisfiesActivationRequirement(), 'validité non configurée');

        $zone->changeValidityMode(ValidityMode::SpecificPeriods, F::now());
        self::assertTrue($zone->satisfiesActivationRequirement(), 'une période, même future, suffit (les créneaux sont vérifiés en base)');

        $zone->changeValidityMode(ValidityMode::Unconfigured, F::now());
        self::assertFalse($zone->satisfiesActivationRequirement());

        $unconfirmed = EventZone::proposeWholeMunicipality($participation, UserBuilder::aUser()->build(), F::now());
        self::assertFalse($unconfirmed->satisfiesActivationRequirement());
    }

    public function testRightsRequireAnActiveEvent(): void
    {
        $event = F::draft();
        $participation = F::organizerParticipation($event);
        $zone = F::readyZone($participation);

        self::assertTrue($zone->satisfiesActivationRequirement());
        self::assertFalse($zone->mayOpenRights(), 'événement en préparation');

        $event->activate(F::now());
        self::assertTrue($zone->mayOpenRights());

        $event->suspend('Motif', F::now());
        self::assertFalse($zone->mayOpenRights(), 'événement suspendu');

        $event->reactivate(F::now());
        $zone->suspend(F::now());
        self::assertFalse($zone->mayOpenRights(), 'zone suspendue');
    }

    public function testBlankOrOversizedLabelIsRefused(): void
    {
        $participation = F::organizerParticipation(F::draft());

        foreach (['  ', str_repeat('x', 151)] as $label) {
            try {
                EventZone::create($participation, $label, ZoneType::Custom, null, UserBuilder::aUser()->build(), F::now());
                self::fail('Libellé invalide accepté.');
            } catch (DomainRuleViolation) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testZoneTypeIsImmutableAfterCreation(): void
    {
        // DM-080 : changer de type = supprimer logiquement la zone et en créer une nouvelle.
        $zone = F::zone(F::organizerParticipation(F::draft()), ZoneType::Street);

        $zone->update('Nouveau libellé', 'Description', F::now());

        self::assertSame(ZoneType::Street, $zone->type());
        foreach ((new \ReflectionClass(EventZone::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertDoesNotMatchRegularExpression('/(set|change|convert|switch)Type|^retype/i', $method->getName());
        }
    }

    public function testZoneHasNoGeometryNorLibraryNorSnapshot(): void
    {
        $properties = array_map(static fn (\ReflectionProperty $p): string => $p->getName(), (new \ReflectionClass(EventZone::class))->getProperties());

        foreach ($properties as $property) {
            self::assertDoesNotMatchRegularExpression('/geo|geometry|polygon|coordinate|snapshot|library|source/i', $property);
        }
    }
}
