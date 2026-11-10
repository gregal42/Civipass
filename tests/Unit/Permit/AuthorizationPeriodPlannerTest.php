<?php

declare(strict_types=1);

namespace App\Tests\Unit\Permit;

use App\Event\Domain\EventStatus;
use App\Event\Domain\Time\AmbiguousLocalTime;
use App\Event\Domain\Time\NonexistentLocalTime;
use App\Event\Domain\Time\Occurrence;
use App\Permit\Domain\AuthorizationPeriodPlanner;
use App\Permit\Domain\PeriodEnd;
use App\Permit\Domain\PeriodKind;
use App\Request\Domain\DurationMode;
use App\Request\Domain\GrantWindow;
use App\Shared\Domain\DomainRuleViolation;
use App\Tests\Support\TimeFactory as T;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Règles de période (LOT 05.5.2, décisions du 10 novembre 2026) : prolongation, reprise après expiration, raccourcissement ; début inclus, fin exclue ;
 * aucune reprise rétroactive ; durée minimale d'une minute ; fin immédiate jamais antidatée ; événement non actif ; fuseaux et changements d'heure (Europe/Paris).
 * Instant de référence : 12 juin 2026 12:00:00 UTC ; fin de l'événement : 20 juin 2026 18:00 UTC.
 */
final class AuthorizationPeriodPlannerTest extends TestCase
{
    private const NOW = '2026-06-12 12:00:00';
    private const EVENT_END = '2026-06-20 18:00:00';

    private AuthorizationPeriodPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new AuthorizationPeriodPlanner();
    }

    private function window(string $from, ?string $until, ?DurationMode $mode = null): GrantWindow
    {
        return new GrantWindow($mode ?? (null === $until ? DurationMode::UntilClosure : DurationMode::Custom), T::utc($from), null === $until ? null : T::utc($until));
    }

    private function extend(GrantWindow $current, PeriodEnd $end, string $now = self::NOW, ?string $eventEnd = self::EVENT_END, EventStatus $status = EventStatus::Active, bool $revoked = false): \App\Permit\Domain\PeriodPlan
    {
        return $this->planner->extend($current, $revoked, $status, null === $eventEnd ? null : T::utc($eventEnd), T::paris(), T::utc($now), $end);
    }

    private function shorten(GrantWindow $current, PeriodEnd $end, string $now = self::NOW, ?string $eventEnd = self::EVENT_END, EventStatus $status = EventStatus::Active, bool $revoked = false): \App\Permit\Domain\PeriodPlan
    {
        return $this->planner->shorten($current, $revoked, $status, null === $eventEnd ? null : T::utc($eventEnd), T::paris(), T::utc($now), $end);
    }

    private function refused(callable $operation, string $contains = ''): void
    {
        try {
            $operation();
            self::fail('Un refus (DomainRuleViolation) est attendu.');
        } catch (DomainRuleViolation $e) {
            self::addToAssertionCount(1);
            if ('' !== $contains) {
                self::assertStringContainsString($contains, $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------ prolongation

    public function testAFixedDurationIsAddedToTheCurrentEndInElapsedHours(): void
    {
        $plan = $this->extend($this->window('2026-06-12 09:00:00', '2026-06-13 09:00:00'), PeriodEnd::fixedHours(DurationMode::H24));

        self::assertSame(PeriodKind::Extended, $plan->kind);
        self::assertEquals(T::utc('2026-06-12 09:00:00'), $plan->from, 'le début ne change jamais');
        self::assertEquals(T::utc('2026-06-14 09:00:00'), $plan->until);
        self::assertSame(DurationMode::Custom, $plan->mode);
        self::assertFalse($plan->archivesCurrent);
        self::assertFalse($plan->immediate);
    }

    public function testACustomLocalEndIsResolvedInTheEventTimezone(): void
    {
        $plan = $this->extend($this->window('2026-06-12 09:00:00', '2026-06-13 09:00:00'), PeriodEnd::custom('2026-06-14', '18:00'));

        self::assertEquals(T::utc('2026-06-14 16:00:00'), $plan->until, '18:00 à Paris en été = 16:00 UTC');
    }

    public function testUntilClosureRemovesTheOwnEnd(): void
    {
        $plan = $this->extend($this->window('2026-06-12 09:00:00', '2026-06-13 09:00:00'), PeriodEnd::untilClosure());

        self::assertNull($plan->until);
        self::assertSame(DurationMode::UntilClosure, $plan->mode);
    }

    public function testAnAuthorizationAlreadyUntilClosureCannotBeExtended(): void
    {
        $this->refused(fn () => $this->extend($this->window('2026-06-12 09:00:00', null), PeriodEnd::fixedHours(DurationMode::H24)), 'jusqu\'à la clôture');
    }

    public function testAnExtensionThatDoesNotAdvanceTheEndOrExceedsTheEventIsRefused(): void
    {
        $current = $this->window('2026-06-12 09:00:00', '2026-06-13 09:00:00'); // fin 09:00 UTC = 11:00 à Paris

        $this->refused(fn () => $this->extend($current, PeriodEnd::custom('2026-06-13', '11:00')), 'au moins une minute'); // identique
        $this->refused(fn () => $this->extend($current, PeriodEnd::custom('2026-06-13', '10:00')), 'au moins une minute'); // antérieure
        $this->refused(fn () => $this->extend($current, PeriodEnd::custom('2026-06-21', '10:00')), 'fin prévue de l\'événement');
        $this->refused(fn () => $this->extend($this->window('2026-06-12 09:00:00', '2026-06-19 09:00:00'), PeriodEnd::fixedHours(DurationMode::H72)), 'fin prévue de l\'événement'); // 22 juin 09:00
        self::assertEquals(T::utc('2026-06-13 09:01:00'), $this->extend($current, PeriodEnd::custom('2026-06-13', '11:01'))->until, 'une minute de plus : permis');
        self::assertEquals(T::utc(self::EVENT_END), $this->extend($current, PeriodEnd::custom('2026-06-20', '20:00'))->until, 'exactement la fin de l\'événement : permis');
    }

    public function testAnExtensionOfAnAuthorizationNotYetStartedOnlyMovesItsEnd(): void
    {
        $plan = $this->extend($this->window('2026-06-14 09:00:00', '2026-06-15 09:00:00'), PeriodEnd::fixedHours(DurationMode::H24));

        self::assertEquals(T::utc('2026-06-14 09:00:00'), $plan->from);
        self::assertEquals(T::utc('2026-06-16 09:00:00'), $plan->until);
        self::assertSame(PeriodKind::Extended, $plan->kind);
    }

    public function testAPeriodEndingExactlyNowIsNotYetExpiredSoItIsExtendedNotResumed(): void
    {
        $plan = $this->extend($this->window('2026-06-11 12:00:00', self::NOW), PeriodEnd::fixedHours(DurationMode::H24));

        self::assertSame(PeriodKind::Extended, $plan->kind, 'aucune interruption : pas de période archivée');
        self::assertFalse($plan->archivesCurrent);
        self::assertEquals(T::utc('2026-06-13 12:00:00'), $plan->until);
    }

    // ------------------------------------------------------------------ reprise

    public function testAnExpiredPeriodIsResumedFromNowWithoutAnyRetroactiveRight(): void
    {
        $plan = $this->extend($this->window('2026-06-10 09:00:00', '2026-06-11 09:00:00'), PeriodEnd::fixedHours(DurationMode::H24));

        self::assertSame(PeriodKind::Resumed, $plan->kind);
        self::assertEquals(T::utc(self::NOW), $plan->from, 'la nouvelle période commence maintenant, jamais à l\'ancien début');
        self::assertEquals(T::utc('2026-06-13 12:00:00'), $plan->until, 'durée fixe ajoutée à maintenant');
        self::assertTrue($plan->archivesCurrent);
        self::assertSame(DurationMode::Custom, $plan->mode);
        self::assertEquals(T::utc('2026-06-11 09:00:00'), $plan->before->until, 'l\'ancienne période est celle qui sera archivée');
    }

    public function testAResumptionCanBeUntilClosure(): void
    {
        $plan = $this->extend($this->window('2026-06-10 09:00:00', '2026-06-11 09:00:00'), PeriodEnd::untilClosure());

        self::assertSame(PeriodKind::Resumed, $plan->kind);
        self::assertNull($plan->until);
        self::assertSame(DurationMode::UntilClosure, $plan->mode);
    }

    public function testAResumptionNeedsAnEventThatHasNotEndedAndNeverExceedsItsEnd(): void
    {
        $expired = $this->window('2026-06-10 09:00:00', '2026-06-11 09:00:00');

        $this->refused(fn () => $this->extend($expired, PeriodEnd::fixedHours(DurationMode::H24), eventEnd: '2026-06-12 11:00:00'), 'prolongez d\'abord');
        $this->refused(fn () => $this->extend($expired, PeriodEnd::fixedHours(DurationMode::H24), eventEnd: '2026-06-12 12:00:00'), 'prolongez d\'abord');
        $this->refused(fn () => $this->extend($expired, PeriodEnd::fixedHours(DurationMode::H72), eventEnd: '2026-06-14 12:00:00'), 'fin prévue de l\'événement');
        self::assertSame(PeriodKind::Resumed, $this->extend($expired, PeriodEnd::fixedHours(DurationMode::H72), eventEnd: '2026-06-15 12:00:00')->kind, 'exactement la fin de l\'événement : permis');
    }

    public function testTheMinimumDurationOfAResumedPeriodIsOneMinute(): void
    {
        $expired = $this->window('2026-06-10 09:00:00', '2026-06-11 09:00:00');

        $this->refused(fn () => $this->extend($expired, PeriodEnd::custom('2026-06-12', '14:00')), 'au moins une minute'); // 12:00:00 UTC = maintenant
        $this->refused(fn () => $this->extend($expired, PeriodEnd::custom('2026-06-12', '14:01'), now: '2026-06-12 12:00:30'), 'au moins une minute'); // 12:01:00 UTC : 30 s seulement
        $plan = $this->extend($expired, PeriodEnd::custom('2026-06-12', '14:01')); // 12:01:00 UTC, exactement 60 s
        self::assertEquals(T::utc('2026-06-12 12:01:00'), $plan->until);
    }

    public function testAResumptionWithADateInThePastIsRefused(): void
    {
        $this->refused(fn () => $this->extend($this->window('2026-06-10 09:00:00', '2026-06-11 09:00:00'), PeriodEnd::custom('2026-06-12', '10:00')), 'au moins une minute');
    }

    // ------------------------------------------------------------------ refus communs

    public function testARevokedAuthorizationRefusesEveryChange(): void
    {
        $current = $this->window('2026-06-12 09:00:00', '2026-06-13 09:00:00');

        $this->refused(fn () => $this->extend($current, PeriodEnd::fixedHours(DurationMode::H24), revoked: true), 'révoquée');
        $this->refused(fn () => $this->shorten($current, PeriodEnd::custom('2026-06-13', '09:00'), revoked: true), 'révoquée');
    }

    /**
     * @return iterable<string, array{EventStatus}>
     */
    public static function inactiveEvents(): iterable
    {
        yield 'suspendu' => [EventStatus::Suspended];
        yield 'clos' => [EventStatus::Closed];
        yield 'brouillon' => [EventStatus::Draft];
    }

    #[DataProvider('inactiveEvents')]
    public function testExtensionAndResumptionAreRefusedWhenTheEventIsNotActive(EventStatus $status): void
    {
        $this->refused(fn () => $this->extend($this->window('2026-06-12 09:00:00', '2026-06-13 09:00:00'), PeriodEnd::fixedHours(DurationMode::H24), status: $status));
        $this->refused(fn () => $this->extend($this->window('2026-06-10 09:00:00', '2026-06-11 09:00:00'), PeriodEnd::fixedHours(DurationMode::H24), status: $status));
    }

    // ------------------------------------------------------------------ raccourcissement

    public function testAFutureEndEarlierThanTheCurrentOneShortensTheCurrentPeriod(): void
    {
        $plan = $this->shorten($this->window('2026-06-12 09:00:00', '2026-06-15 09:00:00'), PeriodEnd::custom('2026-06-13', '18:00'));

        self::assertSame(PeriodKind::Shortened, $plan->kind);
        self::assertEquals(T::utc('2026-06-13 16:00:00'), $plan->until);
        self::assertEquals(T::utc('2026-06-12 09:00:00'), $plan->from);
        self::assertFalse($plan->immediate);
        self::assertFalse($plan->archivesCurrent);
        self::assertSame(DurationMode::Custom, $plan->mode);
        self::assertEquals($plan->until, $plan->requestedUntil);
    }

    public function testAPastDateGivesAnImmediateEndAtNowNeverBackdated(): void
    {
        $plan = $this->shorten($this->window('2026-06-12 09:00:00', '2026-06-15 09:00:00'), PeriodEnd::custom('2026-06-12', '10:00')); // 08:00 UTC, passé

        self::assertTrue($plan->immediate);
        self::assertEquals(T::utc(self::NOW), $plan->until, 'fin appliquée = maintenant');
        self::assertEquals(T::utc('2026-06-12 08:00:00'), $plan->requestedUntil, 'la date demandée est conservée');
        self::assertSame('2026-06-12T12:00:00+00:00', $plan->afterState()['validUntil']);
        self::assertSame('2026-06-12T08:00:00+00:00', $plan->afterState()['requestedUntil']);
        self::assertTrue($plan->afterState()['immediate']);
    }

    public function testTheEndInstantItselfIsAnImmediateEnd(): void
    {
        $plan = $this->shorten($this->window('2026-06-12 09:00:00', '2026-06-15 09:00:00'), PeriodEnd::custom('2026-06-12', '14:00')); // 12:00:00 UTC = maintenant

        self::assertTrue($plan->immediate);
        self::assertEquals(T::utc(self::NOW), $plan->until);
    }

    public function testAFutureEndMustBeMoreThanOneMinuteAway(): void
    {
        $current = $this->window('2026-06-12 09:00:00', '2026-06-15 09:00:00');

        $this->refused(fn () => $this->shorten($current, PeriodEnd::custom('2026-06-12', '14:01'), now: '2026-06-12 12:00:30'), 'plus d\'une minute'); // 12:01:00 UTC : 30 s devant
        self::assertEquals(T::utc('2026-06-12 12:01:00'), $this->shorten($current, PeriodEnd::custom('2026-06-12', '14:01'))->until, 'exactement 60 s : permis');
    }

    public function testTheNewEndMustBeEarlierThanTheEffectiveEnd(): void
    {
        $current = $this->window('2026-06-12 09:00:00', '2026-06-15 09:00:00');

        $this->refused(fn () => $this->shorten($current, PeriodEnd::custom('2026-06-15', '11:00')), 'antérieure à la fin actuelle'); // = 09:00 UTC, identique
        $this->refused(fn () => $this->shorten($current, PeriodEnd::custom('2026-06-16', '11:00')), 'antérieure à la fin actuelle');
        $this->refused(fn () => $this->shorten($current, PeriodEnd::fixedHours(DurationMode::H24)), 'nouvelle fin');
        $this->refused(fn () => $this->shorten($current, PeriodEnd::untilClosure()), 'nouvelle fin');
    }

    public function testAnUntilClosureAuthorizationIsBoundedByTheEventEnd(): void
    {
        $current = $this->window('2026-06-12 09:00:00', null);

        $this->refused(fn () => $this->shorten($current, PeriodEnd::custom('2026-06-21', '10:00')), 'antérieure');
        self::assertEquals(T::utc('2026-06-19 10:00:00'), $this->shorten($current, PeriodEnd::custom('2026-06-19', '12:00'))->until);
        self::assertEquals(T::utc('2026-06-19 10:00:00'), $this->shorten($current, PeriodEnd::custom('2026-06-19', '12:00'), eventEnd: null)->until, 'sans fin d\'événement : toute fin finie raccourcit');
    }

    public function testAnImmediateEndNeedsAStartedPeriodAndOtherwiseRevocationIsTheWay(): void
    {
        // Période démarrée à la seconde même : fin immédiate = début, impossible.
        $this->refused(fn () => $this->shorten($this->window(self::NOW, '2026-06-15 09:00:00'), PeriodEnd::custom('2026-06-12', '10:00')), 'révoquez');
        // Période non commencée : la fin doit rester postérieure au début.
        $this->refused(fn () => $this->shorten($this->window('2026-06-14 09:00:00', '2026-06-16 09:00:00'), PeriodEnd::custom('2026-06-14', '10:00')), 'révoquez'); // 08:00 UTC < début
        self::assertEquals(T::utc('2026-06-15 09:00:00'), $this->shorten($this->window('2026-06-14 09:00:00', '2026-06-16 09:00:00'), PeriodEnd::custom('2026-06-15', '11:00'))->until);
    }

    public function testATerminatedPeriodCannotBeShortened(): void
    {
        $this->refused(fn () => $this->shorten($this->window('2026-06-10 09:00:00', '2026-06-11 09:00:00'), PeriodEnd::custom('2026-06-10', '12:00')), 'déjà terminée');
        $this->refused(fn () => $this->shorten($this->window('2026-06-11 09:00:00', self::NOW), PeriodEnd::custom('2026-06-11', '12:00')), 'déjà terminée');
    }

    public function testShorteningStaysPossibleDuringASuspensionButNotOnAClosedEvent(): void
    {
        $current = $this->window('2026-06-12 09:00:00', '2026-06-15 09:00:00');

        self::assertSame(PeriodKind::Shortened, $this->shorten($current, PeriodEnd::custom('2026-06-13', '12:00'), status: EventStatus::Suspended)->kind, 'retirer des droits reste possible');
        $this->refused(fn () => $this->shorten($current, PeriodEnd::custom('2026-06-13', '12:00'), status: EventStatus::Closed), 'clos');
    }

    // ------------------------------------------------------------------ fuseaux et changements d'heure (Europe/Paris, 2026)

    public function testANonexistentLocalEndIsRefusedAtTheSpringTransition(): void
    {
        $current = $this->window('2026-03-28 09:00:00', '2026-03-28 18:00:00');

        $this->expectException(NonexistentLocalTime::class);
        $this->planner->extend($current, false, EventStatus::Active, null, T::paris(), T::utc('2026-03-28 10:00:00'), PeriodEnd::custom('2026-03-29', '02:30'));
    }

    public function testAnAmbiguousLocalEndNeedsTheChoiceOfTheOccurrenceAtTheAutumnTransition(): void
    {
        $current = $this->window('2026-10-24 09:00:00', '2026-10-24 18:00:00');
        $extend = fn (?Occurrence $o): \DateTimeImmutable => ($this->planner->extend($current, false, EventStatus::Active, null, T::paris(), T::utc('2026-10-24 10:00:00'), PeriodEnd::custom('2026-10-25', '02:30', $o))->until ?? throw new \LogicException());

        try {
            $extend(null);
            self::fail('Un horaire répété sans choix doit être refusé.');
        } catch (AmbiguousLocalTime) {
            // attendu
        }
        self::assertEquals(T::utc('2026-10-25 00:30:00'), $extend(Occurrence::First), 'première fois : 02:30 CEST = 00:30 UTC');
        self::assertEquals(T::utc('2026-10-25 01:30:00'), $extend(Occurrence::Second), 'deuxième fois : 02:30 CET = 01:30 UTC');
    }

    public function testAFixedDurationCrossingTheTransitionsIsInElapsedHoursNotLocalClockTime(): void
    {
        $spring = $this->planner->extend($this->window('2026-03-28 09:00:00', '2026-03-28 12:00:00'), false, EventStatus::Active, null, T::paris(), T::utc('2026-03-28 10:00:00'), PeriodEnd::fixedHours(DurationMode::H24));
        $autumn = $this->planner->extend($this->window('2026-10-24 09:00:00', '2026-10-24 12:00:00'), false, EventStatus::Active, null, T::paris(), T::utc('2026-10-24 10:00:00'), PeriodEnd::fixedHours(DurationMode::H24));

        self::assertEquals(T::utc('2026-03-29 12:00:00'), $spring->until, '24 h écoulées à travers le passage à l\'heure d\'été');
        self::assertEquals(T::utc('2026-10-25 12:00:00'), $autumn->until, '24 h écoulées à travers le retour à l\'heure d\'hiver');
    }

    public function testACustomEndOnTheTransitionDaysIsConvertedWithTheOffsetInForceAtThatInstant(): void
    {
        $current = $this->window('2026-03-28 09:00:00', '2026-03-28 10:00:00');
        $planner = fn (string $date, string $time): \DateTimeImmutable => ($this->planner->extend($current, false, EventStatus::Active, null, T::paris(), T::utc('2026-03-28 09:30:00'), PeriodEnd::custom($date, $time))->until ?? throw new \LogicException());

        self::assertEquals(T::utc('2026-03-28 11:00:00'), $planner('2026-03-28', '12:00'), 'veille : CET, +1');
        self::assertEquals(T::utc('2026-03-29 10:00:00'), $planner('2026-03-29', '12:00'), 'jour du passage : CEST, +2');
        self::assertEquals(T::utc('2026-03-29 01:00:00'), $planner('2026-03-29', '03:00'), '03:00 CEST, juste après le saut');
    }

    public function testAZoneWithoutDstIsRespected(): void
    {
        $reunion = \App\Event\Domain\Time\EventTimezone::fromIdentifier('Indian/Reunion');
        $plan = $this->planner->extend($this->window('2026-06-12 09:00:00', '2026-06-13 09:00:00'), false, EventStatus::Active, null, $reunion, T::utc(self::NOW), PeriodEnd::custom('2026-06-14', '18:00'));

        self::assertEquals(T::utc('2026-06-14 14:00:00'), $plan->until, 'La Réunion : UTC+4 toute l\'année');
    }

    // ------------------------------------------------------------------ états structurés sans donnée personnelle

    public function testTheStructuredStatesCarryOnlyTechnicalValues(): void
    {
        $plan = $this->extend($this->window('2026-06-10 09:00:00', '2026-06-11 09:00:00'), PeriodEnd::fixedHours(DurationMode::H24));

        self::assertSame(['durationMode', 'validFrom', 'validUntil'], array_keys($plan->beforeState()));
        self::assertSame(['durationMode', 'validFrom', 'validUntil', 'archivedPrevious', 'requestedUntil', 'immediate'], array_keys($plan->afterState()));
        self::assertTrue($plan->afterState()['archivedPrevious']);
    }

    public function testInputParsingRefusesInvalidValues(): void
    {
        foreach ([['NOPE'], ['CUSTOM'], ['CUSTOM', '2026-06-14'], ['CUSTOM', 'pas-une-date', '10:00'], ['CUSTOM', '2026-06-14', '10:00', 'third']] as $args) {
            $this->refused(static fn () => PeriodEnd::fromInput(...$args));
        }
        self::assertSame(DurationMode::H48, PeriodEnd::fromInput('H48')->mode);
        self::assertSame(DurationMode::UntilClosure, PeriodEnd::fromInput('UNTIL_CLOSURE')->mode);
        self::assertSame(DurationMode::Custom, PeriodEnd::fromInput('CUSTOM', '2026-06-14', '10:00', 'second')->mode);
    }
}
