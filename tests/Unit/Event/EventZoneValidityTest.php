<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Event\Domain\EventZoneValidity;
use App\Event\Domain\Time\Occurrence;
use App\Event\Domain\Validity\ChoiceField;
use App\Event\Domain\Validity\DstChoice;
use App\Event\Domain\Validity\DstChoices;
use App\Event\Domain\Validity\EventPeriod;
use App\Event\Domain\Validity\InvalidValiditySlot;
use App\Event\Domain\Validity\ValidityKind;
use App\Event\Domain\Validity\ValidityMode;
use App\Event\Domain\Validity\ValiditySlot;
use App\Event\Domain\Validity\Weekdays;
use App\Event\Domain\Validity\ZoneValidityCalculator;
use App\Shared\Domain\DomainRuleViolation;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\TimeFactory as T;
use PHPUnit\Framework\TestCase;

final class EventZoneValidityTest extends TestCase
{
    private static function zone(): \App\Event\Domain\EventZone
    {
        return F::readyZone(F::organizerParticipation(F::draft()));
    }

    /**
     * @return array<string, ValiditySlot>
     */
    private static function slots(): array
    {
        return [
            'continu' => ValiditySlot::continuous(T::date('2026-06-10'), T::time('08:00'), T::date('2026-06-12'), T::time('18:30')),
            'ponctuel' => ValiditySlot::punctual(T::date('2026-06-10'), T::time('09:00'), T::time('17:00')),
            'récurrent' => ValiditySlot::recurring(T::date('2026-06-01'), T::date('2026-06-30'), Weekdays::fromIsoDays([1, 3, 7]), T::time('22:00'), T::time('06:00')),
            'avec choix' => ValiditySlot::punctual(T::date('2026-10-25'), T::time('02:30'), T::time('04:00'), new DstChoices([
                new DstChoice(T::date('2026-10-25'), ChoiceField::Start, Occurrence::Second),
            ])),
        ];
    }

    public function testEverySlotKindRoundTripsThroughTheEntity(): void
    {
        $zone = self::zone();

        foreach (self::slots() as $label => $slot) {
            $entity = EventZoneValidity::fromSlot($zone, $slot, F::now());

            self::assertEquals($slot, $entity->toSlot(), $label);
            self::assertSame($slot->kind, $entity->kind(), $label);
            self::assertSame($zone, $entity->zone());
            self::assertFalse($entity->isDeleted());
        }
    }

    public function testEntityStoresLocalFieldsNeverUtc(): void
    {
        $entity = EventZoneValidity::fromSlot(self::zone(), self::slots()['continu'], F::now());

        $read = static fn (string $property): mixed => (new \ReflectionProperty($entity, $property))->getValue($entity);

        self::assertSame('2026-06-10', $read('startDate')->format('Y-m-d'));
        self::assertSame('08:00', $read('startTime')->format('H:i'));
        self::assertSame('2026-06-12', $read('endDate')->format('Y-m-d'));
        self::assertSame('18:30', $read('endTime')->format('H:i'));
        self::assertNull($read('weekdays'));
        self::assertNull($read('dstChoices'), 'aucun choix : null');
    }

    public function testRecurrenceDaysAndDstChoicesArePersistedInTheirOwnColumns(): void
    {
        $zone = self::zone();
        $recurring = EventZoneValidity::fromSlot($zone, self::slots()['récurrent'], F::now());
        $withChoice = EventZoneValidity::fromSlot($zone, self::slots()['avec choix'], F::now());

        $read = static fn (object $o, string $property): mixed => (new \ReflectionProperty($o, $property))->getValue($o);

        self::assertSame(0b1000101, $read($recurring, 'weekdays'));
        self::assertSame([['date' => '2026-10-25', 'field' => 'start', 'occurrence' => 'second']], $read($withChoice, 'dstChoices'));
    }

    public function testLogicalDeletionKeepsTheSlotReadableForHistory(): void
    {
        $entity = EventZoneValidity::fromSlot(self::zone(), self::slots()['avec choix'], F::now());
        $later = F::now()->modify('+1 day');

        $entity->markDeleted($later);

        self::assertTrue($entity->isDeleted());
        self::assertEquals($later, $entity->deletedAt());
        self::assertEquals($later, $entity->updatedAt());
        $slot = $entity->toSlot();
        self::assertTrue($slot->isDeleted());
        self::assertSame([['date' => '2026-10-25', 'field' => 'start', 'occurrence' => 'second']], $slot->dstChoices->toArray(), 'les choix DST historiques sont conservés');
    }

    public function testDeletionHasNoWayBack(): void
    {
        $entity = EventZoneValidity::fromSlot(self::zone(), self::slots()['ponctuel'], F::now());
        $entity->markDeleted(F::now());

        foreach ((new \ReflectionClass(EventZoneValidity::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertDoesNotMatchRegularExpression('/^(restore|undelete|recover|unmark|reactivate)/i', $method->getName());
        }
        $this->expectException(DomainRuleViolation::class);
        $entity->markDeleted(F::now());
    }

    public function testSlotIsImmutableAfterCreation(): void
    {
        foreach ((new \ReflectionClass(EventZoneValidity::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertDoesNotMatchRegularExpression('/^(set|change|update(?!d)|reschedule|with)/i', $method->getName(), 'modifier un créneau = le remplacer');
        }
    }

    public function testCannotCreateASlotOnADeletedZoneOrFromADeletedSlot(): void
    {
        $zone = self::zone();
        $deletedSlot = self::slots()['ponctuel']->markDeleted(F::now());

        try {
            EventZoneValidity::fromSlot($zone, $deletedSlot, F::now());
            self::fail('Un créneau supprimé ne se crée pas.');
        } catch (DomainRuleViolation) {
            self::addToAssertionCount(1);
        }

        $zone->markDeleted(F::now());
        $this->expectException(DomainRuleViolation::class);
        EventZoneValidity::fromSlot($zone, self::slots()['ponctuel'], F::now());
    }

    public function testEntitiesFeedTheStepOneCalculatorAndDeletedSlotsAreExcluded(): void
    {
        $zone = self::zone();
        $kept = EventZoneValidity::fromSlot($zone, ValiditySlot::punctual(T::date('2026-06-10'), T::time('09:00'), T::time('17:00')), F::now());
        $replaced = EventZoneValidity::fromSlot($zone, ValiditySlot::punctual(T::date('2026-06-11'), T::time('09:00'), T::time('17:00')), F::now());
        $replaced->markDeleted(F::now());
        $replacement = EventZoneValidity::fromSlot($zone, ValiditySlot::punctual(T::date('2026-06-12'), T::time('09:00'), T::time('17:00')), F::now());
        $event = new EventPeriod(T::utc('2026-06-01 00:00'), T::utc('2026-07-01 00:00'));

        $periods = (new ZoneValidityCalculator())->compute(
            ValidityMode::SpecificPeriods,
            $event,
            T::paris(),
            array_map(static fn (EventZoneValidity $e): ValiditySlot => $e->toSlot(), [$kept, $replaced, $replacement]),
        );

        self::assertCount(2, $periods->intervals());
        self::assertTrue($periods->isEffectiveAt(T::utc('2026-06-10 10:00')));
        self::assertFalse($periods->isEffectiveAt(T::utc('2026-06-11 10:00')), 'créneau supprimé exclu');
        self::assertTrue($periods->isEffectiveAt(T::utc('2026-06-12 10:00')));
    }

    public function testAnInvalidSlotRebuiltFromTheEntityStillFailsClosed(): void
    {
        // 29 mars 2026 02:30 n'existe pas à Paris : le créneau stocké est invalide dans ce fuseau.
        $entity = EventZoneValidity::fromSlot(self::zone(), ValiditySlot::punctual(T::date('2026-03-29'), T::time('02:30'), T::time('04:00')), F::now());

        $this->expectException(InvalidValiditySlot::class);
        (new ZoneValidityCalculator())->compute(
            ValidityMode::SpecificPeriods,
            new EventPeriod(T::utc('2026-01-01 00:00'), T::utc('2027-01-01 00:00')),
            T::paris(),
            [$entity->toSlot()],
        );
    }

    public function testKindsAreStoredAsPortableStrings(): void
    {
        self::assertSame(['CONTINUOUS', 'PUNCTUAL', 'RECURRING'], array_map(static fn (ValidityKind $k): string => $k->value, ValidityKind::cases()));
    }
}
