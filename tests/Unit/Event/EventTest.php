<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Event\Domain\Event;
use App\Event\Domain\EventStatus;
use App\Event\Domain\PublicReference;
use App\Event\Domain\Time\EventTimezone;
use App\Shared\Domain\DomainRuleViolation;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    private static function utc(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    public function testConstruction(): void
    {
        $organizer = MunicipalityBuilder::aMunicipality()->build();
        $creator = UserBuilder::aUser()->build();
        $event = new Event(
            PublicReference::fromCounter(154, F::now()),
            $organizer,
            F::type(),
            '  Incendie du massif  ',
            EventTimezone::fromIdentifier('Indian/Reunion'),
            self::utc('2026-06-10 08:00'),
            null,
            $creator,
            F::now(),
            '   ',
            ' Ne pas stationner ',
        );

        self::assertSame(EventStatus::Draft, $event->status());
        self::assertSame('EVT-2026-000154', $event->publicReference()->value);
        self::assertSame('Incendie du massif', $event->name());
        self::assertNull($event->description(), 'description vide ramenée à null');
        self::assertSame('Ne pas stationner', $event->operationalInstructions());
        self::assertSame('Indian/Reunion', $event->timezone()->identifier());
        self::assertNull($event->endsAt());
        self::assertSame($organizer, $event->organizerMunicipality());
        self::assertSame($creator, $event->createdBy());
        self::assertTrue($event->isOrganizedBy($organizer));
        self::assertFalse($event->isOrganizedBy(MunicipalityBuilder::aMunicipality()->build()));
        self::assertNull($event->activatedAt());
        self::assertNull($event->closedAt());
        self::assertFalse($event->isOperational());
    }

    public function testConstructionRules(): void
    {
        $inactive = F::type('works', 'Travaux');
        $inactive->deactivate();
        foreach ([
            'nom vide' => fn () => self::build(name: '  '),
            'nom trop long' => fn () => self::build(name: str_repeat('x', 201)),
            'fin avant début' => fn () => self::build(endsAt: self::utc('2026-06-10 07:00')),
            'fin = début' => fn () => self::build(endsAt: self::utc('2026-06-10 08:00')),
            'type inactif' => fn () => self::build(type: $inactive),
        ] as $label => $build) {
            try {
                $build();
                self::fail($label.' doit être refusé.');
            } catch (DomainRuleViolation) {
                self::addToAssertionCount(1);
            }
        }
    }

    private static function build(string $name = 'Événement', ?\DateTimeImmutable $endsAt = null, ?\App\Event\Domain\EventType $type = null): Event
    {
        return new Event(
            PublicReference::fromCounter(1, F::now()),
            MunicipalityBuilder::aMunicipality()->build(),
            $type ?? F::type(),
            $name,
            EventTimezone::fromIdentifier('Europe/Paris'),
            self::utc('2026-06-10 08:00'),
            $endsAt,
            UserBuilder::aUser()->build(),
            F::now(),
        );
    }

    public function testLifecycle(): void
    {
        $event = F::draft();
        $now = F::now()->modify('+1 hour');

        $event->activate($now);
        self::assertSame(EventStatus::Active, $event->status());
        self::assertEquals($now, $event->activatedAt());
        self::assertTrue($event->isOperational());

        self::assertNull($event->suspendedAt(), 'jamais suspendu');
        $event->suspend('  Risque de glissement de terrain ', $now);
        self::assertSame(EventStatus::Suspended, $event->status());
        self::assertSame('Risque de glissement de terrain', $event->suspensionReason());
        self::assertEquals($now, $event->suspendedAt());
        self::assertFalse($event->isOperational());

        $event->reactivate($now);
        self::assertSame(EventStatus::Active, $event->status());
        self::assertSame('Risque de glissement de terrain', $event->suspensionReason(), 'dernière suspension connue conservée (DM-081)');
        self::assertEquals($now, $event->suspendedAt(), 'date de la dernière suspension conservée (DM-081)');
        self::assertEquals($now, $event->activatedAt(), 'la date d\'activation d\'origine est conservée');

        $event->close($now);
        self::assertSame(EventStatus::Closed, $event->status());
        self::assertEquals($now, $event->closedAt());
        self::assertFalse($event->isOperational());
    }

    public function testANewSuspensionReplacesTheLastKnownReason(): void
    {
        $event = F::active();

        $first = F::now()->modify('+1 day');
        $second = F::now()->modify('+3 days');

        $event->suspend('Premier motif', $first);
        $event->reactivate($first->modify('+1 hour'));
        self::assertSame(EventStatus::Active, $event->status(), 'seul le statut courant fait autorité');
        self::assertEquals($first, $event->suspendedAt());
        $event->suspend('Second motif', $second);

        self::assertSame('Second motif', $event->suspensionReason());
        self::assertEquals($second, $event->suspendedAt(), 'nouvelle suspension : date actualisée');
    }

    public function testSuspensionRequiresAReason(): void
    {
        $event = F::active();

        $this->expectException(DomainRuleViolation::class);
        $event->suspend('   ', F::now());
    }

    public function testOnlyASuspendedEventCanBeReactivated(): void
    {
        $this->expectException(DomainRuleViolation::class);
        F::active()->reactivate(F::now());
    }

    #[DataProvider('statuses')]
    public function testTransitionsFollowTheStatusTable(EventStatus $from, EventStatus $to): void
    {
        $event = self::inStatus($from);
        $apply = match ($to) {
            EventStatus::Active => static fn () => EventStatus::Suspended === $from ? $event->reactivate(F::now()) : $event->activate(F::now()),
            EventStatus::Suspended => static fn () => $event->suspend('Motif', F::now()),
            EventStatus::Closed => static fn () => $event->close(F::now()),
            EventStatus::Draft => null,
        };

        if (null === $apply || !$from->canTransitionTo($to)) {
            self::assertFalse($from->canTransitionTo($to));
            if (null !== $apply) {
                $this->expectException(DomainRuleViolation::class);
                $apply();
            }

            return;
        }
        $apply();
        self::assertSame($to, $event->status());
    }

    /**
     * @return iterable<string, array{EventStatus, EventStatus}>
     */
    public static function statuses(): iterable
    {
        foreach (EventStatus::cases() as $from) {
            foreach (EventStatus::cases() as $to) {
                yield $from->value.' → '.$to->value => [$from, $to];
            }
        }
    }

    private static function inStatus(EventStatus $status): Event
    {
        $event = F::draft();
        match ($status) {
            EventStatus::Draft => null,
            EventStatus::Active => $event->activate(F::now()),
            EventStatus::Suspended => (function () use ($event): void {
                $event->activate(F::now());
                $event->suspend('Motif', F::now());
            })(),
            EventStatus::Closed => $event->close(F::now()),
        };

        return $event;
    }

    public function testDraftCanBeClosedWithoutBeingActivated(): void
    {
        $event = F::draft();

        $event->close(F::now());

        self::assertSame(EventStatus::Closed, $event->status());
        self::assertNull($event->activatedAt());
        self::assertNotNull($event->closedAt());
    }

    public function testClosureIsDefinitive(): void
    {
        foreach ([EventStatus::Draft, EventStatus::Active, EventStatus::Suspended] as $from) {
            $event = self::inStatus($from);
            $event->close(F::now());

            foreach ([
                fn () => $event->activate(F::now()),
                fn () => $event->reactivate(F::now()),
                fn () => $event->suspend('Motif', F::now()),
                fn () => $event->close(F::now()),
                fn () => $event->rename('Autre', F::now()),
                fn () => $event->describe('Autre', F::now()),
                fn () => $event->changeOperationalInstructions('Autre', F::now()),
                fn () => $event->changeType(F::type('flood', 'Inondation'), F::now()),
                fn () => $event->changeTimezone(EventTimezone::fromIdentifier('Indian/Reunion'), F::now()),
                fn () => $event->reschedule(self::utc('2026-06-10 08:00'), null, F::now()),
            ] as $attempt) {
                try {
                    $attempt();
                    self::fail('Un événement clôturé est définitivement en lecture seule.');
                } catch (DomainRuleViolation) {
                    self::addToAssertionCount(1);
                }
            }
            self::assertSame(EventStatus::Closed, $event->status());
        }
    }

    public function testDraftIsFullyEditable(): void
    {
        $event = F::draft();
        $flood = F::type('flood', 'Inondation');

        $event->changeType($flood, F::now());
        $event->changeTimezone(EventTimezone::fromIdentifier('Pacific/Noumea'), F::now());
        $event->reschedule(self::utc('2026-07-01 00:00'), self::utc('2026-07-05 00:00'), F::now());

        self::assertSame($flood, $event->type());
        self::assertSame('Pacific/Noumea', $event->timezone()->identifier());
        self::assertEquals(self::utc('2026-07-01 00:00'), $event->startsAt());
    }

    public function testStructuringFieldsAreLockedAfterActivation(): void
    {
        foreach ([EventStatus::Active, EventStatus::Suspended] as $status) {
            $event = self::inStatus($status);
            $organizer = $event->organizerMunicipality();
            $reference = $event->publicReference()->value;

            foreach ([
                'type' => fn () => $event->changeType(F::type('flood', 'Inondation'), F::now()),
                'fuseau' => fn () => $event->changeTimezone(EventTimezone::fromIdentifier('Indian/Reunion'), F::now()),
                'date de début' => fn () => $event->reschedule(self::utc('2026-06-09 08:00'), self::utc('2026-06-20 18:00'), F::now()),
            ] as $field => $attempt) {
                try {
                    $attempt();
                    self::fail($field.' doit être verrouillé après l\'activation.');
                } catch (DomainRuleViolation $e) {
                    self::assertStringContainsString('verrouillé', $e->getMessage());
                }
            }
            self::assertSame($organizer, $event->organizerMunicipality());
            self::assertSame($reference, $event->publicReference()->value);
            self::assertSame('Europe/Paris', $event->timezone()->identifier());
        }
    }

    public function testOrganizerAndPublicReferenceHaveNoMutator(): void
    {
        $methods = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(Event::class))->getMethods(\ReflectionMethod::IS_PUBLIC));

        foreach ($methods as $method) {
            self::assertDoesNotMatchRegularExpression('/^(set|change|replace|transfer)(Organizer|PublicReference|Reference)/i', $method);
        }
    }

    public function testEditableFieldsAfterActivation(): void
    {
        foreach ([EventStatus::Active, EventStatus::Suspended] as $status) {
            $event = self::inStatus($status);
            $later = F::now()->modify('+2 days');

            $event->rename('Nouveau nom', $later);
            $event->describe('Nouvelle description', $later);
            $event->changeOperationalInstructions('Accès par la RD 107 uniquement.', $later);
            $event->reschedule(self::utc('2026-06-10 08:00'), self::utc('2026-06-25 18:00'), $later);

            self::assertSame('Nouveau nom', $event->name());
            self::assertSame('Nouvelle description', $event->description());
            self::assertSame('Accès par la RD 107 uniquement.', $event->operationalInstructions());
            self::assertEquals(self::utc('2026-06-25 18:00'), $event->endsAt());
            self::assertEquals($later, $event->updatedAt());
        }
    }

    public function testEndDateAfterActivationCanBeRemovedOrReduced(): void
    {
        $event = F::active();

        $event->reschedule(self::utc('2026-06-10 08:00'), null, F::now());
        self::assertNull($event->endsAt());

        $event->reschedule(self::utc('2026-06-10 08:00'), self::utc('2026-06-12 08:00'), F::now());
        self::assertEquals(self::utc('2026-06-12 08:00'), $event->endsAt());
    }

    public function testEndDateAfterActivationCannotBeSetInThePast(): void
    {
        $event = F::active();

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('clôturez');
        $event->reschedule(self::utc('2026-06-10 08:00'), self::utc('2026-06-10 09:00'), self::utc('2026-06-15 00:00'));
    }

    public function testInstructionsCanBeClearedAndNameMustStayValid(): void
    {
        $event = F::active();
        $event->changeOperationalInstructions('Consigne', F::now());
        $event->changeOperationalInstructions('  ', F::now());

        self::assertNull($event->operationalInstructions());
        $this->expectException(DomainRuleViolation::class);
        $event->rename(' ', F::now());
    }
}
