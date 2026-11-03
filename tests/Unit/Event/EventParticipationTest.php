<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Event\Domain\EventParticipation;
use App\Event\Domain\ParticipationStatus;
use App\Shared\Domain\DomainRuleViolation;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\TestCase;

final class EventParticipationTest extends TestCase
{
    public function testOrganizerParticipationIsActiveFromTheStart(): void
    {
        $event = F::draft();

        $participation = F::organizerParticipation($event);

        self::assertSame(ParticipationStatus::Active, $participation->status());
        self::assertSame($event->organizerMunicipality(), $participation->municipality());
        self::assertTrue($participation->isOrganizer());
        self::assertNull($participation->invitedBy());
        self::assertNull($participation->invitedAt());
        self::assertEquals(F::now(), $participation->activatedAt());
        self::assertTrue($participation->allowsZoneEdition(), 'l\'organisatrice prépare ses zones dès le brouillon');
        self::assertFalse($participation->isOperational(), 'aucun accès opérationnel tant que l\'événement n\'est pas actif');
    }

    public function testInvitation(): void
    {
        $event = F::draft();
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $by = UserBuilder::aUser()->build();

        $participation = EventParticipation::invite($event, $municipality, $by, '  Merci de vous joindre à nous.  ', F::now());

        self::assertSame(ParticipationStatus::Invited, $participation->status());
        self::assertSame($municipality, $participation->municipality());
        self::assertSame($event, $participation->event());
        self::assertSame($by, $participation->invitedBy());
        self::assertSame('Merci de vous joindre à nous.', $participation->invitationMessage());
        self::assertEquals(F::now(), $participation->invitedAt());
        self::assertEquals(F::now(), $participation->lastInvitationSentAt());
        self::assertNull($participation->activatedAt());
        self::assertFalse($participation->isOrganizer());
        self::assertFalse($participation->allowsZoneEdition());
    }

    public function testTheOrganizerCannotBeInvitedToItsOwnEvent(): void
    {
        $event = F::draft();

        $this->expectException(DomainRuleViolation::class);
        EventParticipation::invite($event, $event->organizerMunicipality(), UserBuilder::aUser()->build(), null, F::now());
    }

    public function testNoParticipationOnAClosedEvent(): void
    {
        $event = F::draft();
        $event->close(F::now());

        foreach ([
            fn () => F::organizerParticipation($event),
            fn () => F::invited($event),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Un événement clôturé n\'accepte plus de participation.');
            } catch (DomainRuleViolation) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAcceptance(): void
    {
        $participation = F::invited(F::active());
        $later = F::now()->modify('+1 day');

        $participation->accept($later);

        self::assertSame(ParticipationStatus::Active, $participation->status());
        self::assertEquals($later, $participation->activatedAt());
        self::assertTrue($participation->allowsZoneEdition());
        self::assertTrue($participation->isOperational());
    }

    public function testOnlyAPendingInvitationCanBeAcceptedOrResent(): void
    {
        $accepted = F::accepted(F::active());

        foreach ([fn () => $accepted->accept(F::now()), fn () => $accepted->resendInvitation(F::now())] as $attempt) {
            try {
                $attempt();
                self::fail('Seule une invitation en attente peut être acceptée ou renvoyée.');
            } catch (DomainRuleViolation) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testInvitationResendKeepsTheOriginalInvitationDate(): void
    {
        $participation = F::invited(F::draft());
        $later = F::now()->modify('+3 days');

        $participation->resendInvitation($later);

        self::assertEquals(F::now(), $participation->invitedAt());
        self::assertEquals($later, $participation->lastInvitationSentAt());
        self::assertSame(ParticipationStatus::Invited, $participation->status());
    }

    public function testAnInvitationOnAClosedEventCannotBeAcceptedOrResent(): void
    {
        $event = F::draft();
        $invited = F::invited($event);
        $event->close(F::now());

        foreach ([fn () => $invited->accept(F::now()), fn () => $invited->resendInvitation(F::now())] as $attempt) {
            try {
                $attempt();
                self::fail('L\'événement est clôturé.');
            } catch (DomainRuleViolation) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSuspensionAndReactivation(): void
    {
        $participation = F::accepted(F::active());
        $later = F::now()->modify('+1 day');

        $participation->suspend('  Évacuation en cours  ', $later);
        self::assertSame(ParticipationStatus::Suspended, $participation->status());
        self::assertSame('Évacuation en cours', $participation->suspensionReason());
        self::assertEquals($later, $participation->suspendedAt());
        self::assertTrue($participation->allowsZoneEdition(), 'les zones restent modifiables (DM-066)');
        self::assertFalse($participation->isOperational(), 'mais ne sont jamais opérationnelles');

        $participation->reactivate($later->modify('+1 hour'));
        self::assertSame(ParticipationStatus::Active, $participation->status());
        self::assertSame('Évacuation en cours', $participation->suspensionReason(), 'dernière suspension connue conservée (DM-081)');
        self::assertEquals($later, $participation->suspendedAt());
    }

    public function testANewSuspensionReplacesTheLastKnownOne(): void
    {
        $participation = F::accepted(F::active());
        $first = F::now()->modify('+1 day');
        $second = F::now()->modify('+3 days');

        $participation->suspend('Premier motif', $first);
        $participation->reactivate($first->modify('+1 hour'));
        $participation->suspend('Second motif', $second);

        self::assertSame(ParticipationStatus::Suspended, $participation->status());
        self::assertSame('Second motif', $participation->suspensionReason());
        self::assertEquals($second, $participation->suspendedAt());
    }

    public function testSuspensionFieldsAreNotProofOfAnOngoingSuspension(): void
    {
        $participation = F::accepted(F::active());
        $participation->suspend('Motif', F::now());
        $participation->reactivate(F::now());

        self::assertNotNull($participation->suspendedAt());
        self::assertSame(ParticipationStatus::Active, $participation->status(), 'seul le statut courant fait autorité');
        self::assertTrue($participation->isOperational());
    }

    public function testSuspensionRequiresAReasonAndAnActiveParticipation(): void
    {
        $active = F::accepted(F::active());
        $invited = F::invited(F::active());

        foreach ([fn () => $active->suspend('  ', F::now()), fn () => $invited->suspend('Motif', F::now())] as $attempt) {
            try {
                $attempt();
                self::fail('Suspension refusée.');
            } catch (DomainRuleViolation) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testReactivationIsPossibleOnlyWhileTheEventIsNotClosed(): void
    {
        $event = F::active();
        $participation = F::accepted($event);
        $participation->suspend('Motif', F::now());
        $event->close(F::now());

        $this->expectException(DomainRuleViolation::class);
        $participation->reactivate(F::now());
    }

    public function testAParticipationInASuspendedEventIsNotOperational(): void
    {
        $event = F::active();
        $participation = F::accepted($event);
        self::assertTrue($participation->isOperational());

        $event->suspend('Motif', F::now());

        self::assertFalse($participation->isOperational());
        self::assertSame(ParticipationStatus::Active, $participation->status());
    }

    public function testAParticipantClosesItsOwnParticipation(): void
    {
        $active = F::accepted(F::active());
        $suspended = F::accepted(F::active());
        $suspended->suspend('Motif', F::now());

        $active->close(F::now());
        $suspended->close(F::now());

        self::assertSame(ParticipationStatus::Closed, $active->status());
        self::assertSame(ParticipationStatus::Closed, $suspended->status());
        self::assertEquals(F::now(), $active->closedAt());
        self::assertFalse($active->allowsZoneEdition());
    }

    public function testOrganizerAndPendingInvitationsAreNotClosedOnTheirOwn(): void
    {
        $event = F::active();
        $organizer = F::organizerParticipation($event);
        $invited = F::invited($event);

        foreach ([fn () => $organizer->close(F::now()), fn () => $invited->close(F::now())] as $attempt) {
            try {
                $attempt();
                self::fail('Clôture isolée refusée.');
            } catch (DomainRuleViolation) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEventClosureClosesEveryParticipationIncludingInvitationsAndTheOrganizer(): void
    {
        $event = F::active();
        $organizer = F::organizerParticipation($event);
        $invited = F::invited($event);
        $active = F::accepted($event);
        $suspended = F::accepted($event);
        $suspended->suspend('Motif', F::now());
        $event->close(F::now());

        foreach ([$organizer, $invited, $active, $suspended] as $participation) {
            $participation->closeWithEvent(F::now());
            self::assertSame(ParticipationStatus::Closed, $participation->status());
            self::assertNotNull($participation->closedAt());
        }
    }

    public function testEventClosureFromDraftClosesOrganizerAndInvitations(): void
    {
        $event = F::draft();
        $organizer = F::organizerParticipation($event);
        $invited = F::invited($event);
        $event->close(F::now());

        $organizer->closeWithEvent(F::now());
        $invited->closeWithEvent(F::now());

        self::assertSame(ParticipationStatus::Closed, $organizer->status());
        self::assertSame(ParticipationStatus::Closed, $invited->status());
    }

    public function testParticipationsCannotBeClosedWithAnEventThatIsStillOpen(): void
    {
        $participation = F::invited(F::active());

        $this->expectException(DomainRuleViolation::class);
        $participation->closeWithEvent(F::now());
    }

    public function testClosedParticipationIsDefinitive(): void
    {
        $event = F::active();
        $participation = F::accepted($event);
        $participation->close(F::now());

        foreach ([
            fn () => $participation->accept(F::now()),
            fn () => $participation->suspend('Motif', F::now()),
            fn () => $participation->reactivate(F::now()),
            fn () => $participation->close(F::now()),
            function () use ($participation, $event): void {
                $event->close(F::now());
                $participation->closeWithEvent(F::now());
            },
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Une participation clôturée est définitive.');
            } catch (DomainRuleViolation) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEveryCommunityHasItsOwnParticipationObject(): void
    {
        // Chaque participation ne porte que sa propre commune : aucune méthode ne prend une autre participation.
        $event = F::active();
        $a = F::accepted($event);
        $b = F::accepted($event);

        $a->suspend('Motif', F::now());

        self::assertSame(ParticipationStatus::Active, $b->status());
        self::assertNotSame($a->municipality(), $b->municipality());
        foreach ((new \ReflectionClass(EventParticipation::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                self::assertNotSame(EventParticipation::class, $parameter->getType()?->__toString(), $method->getName());
            }
        }
    }

    public function testStatusTransitionTable(): void
    {
        self::assertSame(
            [
                'INVITED' => ['ACTIVE', 'CLOSED'],
                'ACTIVE' => ['SUSPENDED', 'CLOSED'],
                'SUSPENDED' => ['ACTIVE', 'CLOSED'],
                'CLOSED' => [],
            ],
            array_combine(
                array_map(static fn (ParticipationStatus $s): string => $s->value, ParticipationStatus::cases()),
                array_map(static fn (ParticipationStatus $s): array => array_map(static fn (ParticipationStatus $t): string => $t->value, $s->allowedTransitions()), ParticipationStatus::cases()),
            ),
        );
    }
}
