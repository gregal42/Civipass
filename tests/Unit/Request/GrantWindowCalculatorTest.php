<?php

declare(strict_types=1);

namespace App\Tests\Unit\Request;

use App\Event\Domain\Time\AmbiguousLocalTime;
use App\Event\Domain\Time\NonexistentLocalTime;
use App\Event\Domain\Time\Occurrence;
use App\Event\Domain\Validity\EffectivePeriods;
use App\Event\Domain\Validity\UtcInterval;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\GrantWindow;
use App\Request\Domain\GrantWindowCalculator;
use App\Shared\Domain\DomainRuleViolation;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\TimeFactory as T;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GrantWindowCalculatorTest extends TestCase
{
    private GrantWindowCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new GrantWindowCalculator();
    }

    #[DataProvider('fixedDurations')]
    public function testFixedDurationsAreExactAbsoluteHours(DurationMode $mode, int $hours): void
    {
        $decidedAt = T::utc('2026-06-12 09:00:00');
        $window = $this->calculator->window(ApprovalDuration::fixed($mode), $decidedAt, T::paris(), T::utc('2026-06-20 18:00:00'));

        self::assertEquals($decidedAt, $window->from);
        self::assertNotNull($window->until);
        self::assertSame($hours * 3600, $window->until->getTimestamp() - $window->from->getTimestamp());
        self::assertSame('UTC', $window->until->getTimezone()->getName());
    }

    /**
     * @return iterable<string, array{DurationMode, int}>
     */
    public static function fixedDurations(): iterable
    {
        yield '24 h' => [DurationMode::H24, 24];
        yield '48 h' => [DurationMode::H48, 48];
        yield '72 h' => [DurationMode::H72, 72];
    }

    public function testFixedDurationAcrossADstChangeStaysExactlyInAbsoluteHours(): void
    {
        // Passage à l'heure d'été (Europe/Paris) le 29 mars 2026 : 24 h absolues, pas « le même horaire le lendemain ».
        $window = $this->calculator->window(ApprovalDuration::fixed(DurationMode::H24), T::utc('2026-03-28 12:00:00'), T::paris(), null);

        self::assertSame('2026-03-29 12:00:00', $window->until?->format('Y-m-d H:i:s'));
    }

    public function testFixedDurationMayExceedTheEventEndAndIsCappedOnlyWhenEvaluated(): void
    {
        $window = $this->calculator->window(ApprovalDuration::fixed(DurationMode::H72), T::utc('2026-06-19 12:00:00'), T::paris(), T::utc('2026-06-20 18:00:00'));

        self::assertSame('2026-06-22 12:00:00', $window->until?->format('Y-m-d H:i:s'));
    }

    public function testUntilClosureKeepsNoEndDate(): void
    {
        $window = $this->calculator->window(ApprovalDuration::untilClosure(), T::utc('2026-06-12 09:00:00'), T::paris(), T::utc('2026-06-20 18:00:00'));

        self::assertNull($window->until);
        self::assertSame(DurationMode::UntilClosure, $window->mode);
    }

    public function testCustomEndIsConvertedFromTheEventTimezone(): void
    {
        $window = $this->calculator->window(ApprovalDuration::custom('2026-06-15', '18:30'), T::utc('2026-06-12 09:00:00'), T::paris(), T::utc('2026-06-20 18:00:00'));

        self::assertSame('2026-06-15 16:30:00', $window->until?->format('Y-m-d H:i:s')); // UTC+2 en juin
    }

    public function testCustomEndEqualToTheEventEndIsAccepted(): void
    {
        $window = $this->calculator->window(ApprovalDuration::custom('2026-06-20', '20:00'), T::utc('2026-06-12 09:00:00'), T::paris(), T::utc('2026-06-20 18:00:00'));

        self::assertSame('2026-06-20 18:00:00', $window->until?->format('Y-m-d H:i:s'));
    }

    #[DataProvider('invalidCustomEnds')]
    public function testInvalidCustomEndsAreRefused(string $date, string $time, string $message): void
    {
        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage($message);
        $this->calculator->window(ApprovalDuration::custom($date, $time), T::utc('2026-06-12 09:00:00'), T::paris(), T::utc('2026-06-20 18:00:00'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidCustomEnds(): iterable
    {
        yield 'avant la décision' => ['2026-06-12', '10:00', 'postérieure à la décision'];   // 08:00 UTC < 09:00 UTC
        yield 'égale à la décision' => ['2026-06-12', '11:00', 'postérieure à la décision']; // 09:00 UTC
        yield 'au-delà de l\'événement' => ['2026-06-20', '20:01', 'dépasser la fin prévue de l\'événement'];
    }

    public function testCustomEndWithoutEventEndIsOnlyBoundedByTheDecision(): void
    {
        $window = $this->calculator->window(ApprovalDuration::custom('2030-01-01', '10:00'), T::utc('2026-06-12 09:00:00'), T::paris(), null);

        self::assertSame('2030-01-01 09:00:00', $window->until?->format('Y-m-d H:i:s'));
    }

    public function testNonexistentLocalEndIsRefused(): void
    {
        $this->expectException(NonexistentLocalTime::class);
        $this->calculator->window(ApprovalDuration::custom('2026-03-29', '02:30'), T::utc('2026-03-01 09:00:00'), T::paris(), null);
    }

    public function testAmbiguousLocalEndNeedsAnExplicitChoice(): void
    {
        try {
            $this->calculator->window(ApprovalDuration::custom('2026-10-25', '02:30'), T::utc('2026-10-01 09:00:00'), T::paris(), null);
            self::fail('Une heure répétée sans choix doit être refusée.');
        } catch (AmbiguousLocalTime) {
        }
        $first = $this->calculator->window(ApprovalDuration::custom('2026-10-25', '02:30', Occurrence::First), T::utc('2026-10-01 09:00:00'), T::paris(), null);
        $second = $this->calculator->window(ApprovalDuration::custom('2026-10-25', '02:30', Occurrence::Second), T::utc('2026-10-01 09:00:00'), T::paris(), null);

        self::assertSame('2026-10-25 00:30:00', $first->until?->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-25 01:30:00', $second->until?->format('Y-m-d H:i:s'));
    }

    public function testInputParsingRequiresTheEndOnlyForCustomDurations(): void
    {
        self::assertSame(DurationMode::H48, ApprovalDuration::fromInput('H48')->mode);
        self::assertSame(DurationMode::Custom, ApprovalDuration::fromInput('CUSTOM', '2026-06-15', '18:30')->mode);
        foreach ([['CUSTOM', null, null], ['CUSTOM', '2026-06-15', ''], ['H24', '2026-06-15', '10:00'], ['INCONNU', null, null], ['CUSTOM', 'pas-une-date', '10:00']] as [$mode, $date, $time]) {
            try {
                ApprovalDuration::fromInput($mode, $date, $time);
                self::fail(sprintf('« %s » aurait dû être refusé.', $mode));
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testGrantWindowInvariants(): void
    {
        foreach ([
            [DurationMode::UntilClosure, T::utc('2026-06-13 09:00:00')],
            [DurationMode::H24, null],
            [DurationMode::H24, T::utc('2026-06-12 09:00:00')],
        ] as [$mode, $until]) {
            try {
                new GrantWindow($mode, T::utc('2026-06-12 09:00:00'), $until);
                self::fail('Fenêtre incohérente acceptée.');
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEffectivePeriodsAreTheIntersectionOfWindowEventAndZone(): void
    {
        $participation = F::organizerParticipation(F::active()); // événement du 10 au 20 juin, actif
        $zone = new EffectivePeriods([
            new UtcInterval(T::utc('2026-06-13 06:00:00'), T::utc('2026-06-13 10:00:00')),
            new UtcInterval(T::utc('2026-06-14 06:00:00'), T::utc('2026-06-14 10:00:00')),
            new UtcInterval(T::utc('2026-06-19 06:00:00'), T::utc('2026-06-19 10:00:00')),
        ]);
        $window = $this->calculator->window(ApprovalDuration::fixed(DurationMode::H72), T::utc('2026-06-12 09:00:00'), T::paris(), T::utc('2026-06-20 18:00:00'));

        $effective = $this->calculator->effectivePeriods($window, $zone, $participation)->intervals();

        // Fenêtre : 12 juin 09:00 → 15 juin 09:00. Le créneau du 19 est hors fenêtre ; aucune durée n'est prolongée pour le rattraper.
        self::assertCount(2, $effective);
        self::assertSame('2026-06-13 06:00:00', $effective[0]->start->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-14 10:00:00', $effective[1]->end?->format('Y-m-d H:i:s'));
    }

    public function testAZoneSlotStartingAfterTheDecisionGivesNoImmediateAccess(): void
    {
        $participation = F::organizerParticipation(F::active());
        $zone = new EffectivePeriods([new UtcInterval(T::utc('2026-06-14 06:00:00'), T::utc('2026-06-14 10:00:00'))]);
        $window = $this->calculator->window(ApprovalDuration::untilClosure(), T::utc('2026-06-12 09:00:00'), T::paris(), T::utc('2026-06-20 18:00:00'));
        $effective = $this->calculator->effectivePeriods($window, $zone, $participation);

        self::assertFalse($effective->isEffectiveAt(T::utc('2026-06-12 09:00:00')));
        self::assertTrue($effective->isEffectiveAt(T::utc('2026-06-14 07:00:00')));
    }

    public function testUntilClosureIsBoundedByTheCurrentEventStateNotByAStoredDate(): void
    {
        $event = F::active();
        $participation = F::organizerParticipation($event);
        $zone = new EffectivePeriods([new UtcInterval($event->startsAt(), $event->endsAt())]);
        $window = $this->calculator->window(ApprovalDuration::untilClosure(), T::utc('2026-06-12 09:00:00'), T::paris(), $event->endsAt());

        self::assertTrue($this->calculator->effectivePeriods($window, $zone, $participation)->isEffectiveAt(T::utc('2026-06-15 12:00:00')));

        // Clôture forcée le 15 juin à 14:00 : la participation n'est plus opérationnelle, plus aucun droit effectif.
        $event->close(T::utc('2026-06-15 14:00:00'));
        $participation->closeWithEvent(T::utc('2026-06-15 14:00:00'));
        self::assertTrue($this->calculator->effectivePeriods($window, $zone, $participation)->isEmpty());
    }

    public function testASuspendedParticipationHasNoEffectivePeriod(): void
    {
        $participation = F::organizerParticipation(F::active());
        $participation->suspend('Incident', T::utc('2026-06-12 09:00:00'));
        $window = $this->calculator->window(ApprovalDuration::untilClosure(), T::utc('2026-06-12 09:00:00'), T::paris(), null);
        $zone = new EffectivePeriods([new UtcInterval(T::utc('2026-06-10 08:00:00'), T::utc('2026-06-20 18:00:00'))]);

        self::assertTrue($this->calculator->effectivePeriods($window, $zone, $participation)->isEmpty());
    }
}
