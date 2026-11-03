<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event\Domain\Time;

use App\Event\Domain\Time\AmbiguousLocalTime;
use App\Event\Domain\Time\LocalTimeResolver;
use App\Event\Domain\Time\NonexistentLocalTime;
use App\Event\Domain\Time\Occurrence;
use App\Tests\Support\TimeFactory as T;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalTimeResolverTest extends TestCase
{
    private LocalTimeResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new LocalTimeResolver();
    }

    public function testWinterAndSummerOffsetsInParis(): void
    {
        self::assertEquals(T::utc('2026-01-15 11:00'), $this->resolver->resolve(T::date('2026-01-15'), T::time('12:00'), T::paris()));
        self::assertEquals(T::utc('2026-07-01 10:00'), $this->resolver->resolve(T::date('2026-07-01'), T::time('12:00'), T::paris()));
    }

    public function testSpringForwardGapIsRefused(): void
    {
        // 29 mars 2026 : 02:00 devient 03:00 en Europe/Paris.
        foreach (['02:00', '02:30', '02:59'] as $time) {
            self::assertSame([], $this->resolver->candidates(T::date('2026-03-29'), T::time($time), T::paris()), $time);
        }
        $this->expectException(NonexistentLocalTime::class);
        $this->expectExceptionMessage('02:30 du 2026-03-29 n\'existe pas');
        $this->resolver->resolve(T::date('2026-03-29'), T::time('02:30'), T::paris());
    }

    public function testTimesAroundTheSpringGapAreValid(): void
    {
        self::assertEquals(T::utc('2026-03-29 00:59'), $this->resolver->resolve(T::date('2026-03-29'), T::time('01:59'), T::paris()));
        self::assertEquals(T::utc('2026-03-29 01:00'), $this->resolver->resolve(T::date('2026-03-29'), T::time('03:00'), T::paris()));
        self::assertCount(1, $this->resolver->candidates(T::date('2026-03-28'), T::time('02:30'), T::paris()));
    }

    public function testFallBackRepeatedHourHasTwoInstants(): void
    {
        // 25 octobre 2026 : 03:00 redevient 02:00 en Europe/Paris.
        $candidates = $this->resolver->candidates(T::date('2026-10-25'), T::time('02:30'), T::paris());

        self::assertEquals([T::utc('2026-10-25 00:30'), T::utc('2026-10-25 01:30')], $candidates);
        foreach (['02:00', '02:59'] as $time) {
            self::assertCount(2, $this->resolver->candidates(T::date('2026-10-25'), T::time($time), T::paris()), $time);
        }
        foreach (['01:59', '03:00'] as $time) {
            self::assertCount(1, $this->resolver->candidates(T::date('2026-10-25'), T::time($time), T::paris()), $time);
        }
    }

    public function testAmbiguousTimeRequiresAnExplicitChoice(): void
    {
        $this->expectException(AmbiguousLocalTime::class);
        $this->expectExceptionMessage('se produit deux fois');
        $this->resolver->resolve(T::date('2026-10-25'), T::time('02:30'), T::paris());
    }

    public function testExplicitChoiceSelectsTheOccurrence(): void
    {
        $date = T::date('2026-10-25');
        $time = T::time('02:30');

        self::assertEquals(T::utc('2026-10-25 00:30'), $this->resolver->resolve($date, $time, T::paris(), Occurrence::First));
        self::assertEquals(T::utc('2026-10-25 01:30'), $this->resolver->resolve($date, $time, T::paris(), Occurrence::Second));
    }

    public function testChoiceIsIgnoredWhenTheTimeIsNotAmbiguous(): void
    {
        self::assertEquals(
            T::utc('2026-07-01 10:00'),
            $this->resolver->resolve(T::date('2026-07-01'), T::time('12:00'), T::paris(), Occurrence::Second),
        );
    }

    public function testSaintPierreEtMiquelonObservesDaylightSavingTime(): void
    {
        $zone = T::zone('America/Miquelon');

        // 8 mars 2026 : 02:00 devient 03:00 (UTC-3 → UTC-2).
        self::assertSame([], $this->resolver->candidates(T::date('2026-03-08'), T::time('02:30'), $zone));
        self::assertEquals(T::utc('2026-03-08 05:00'), $this->resolver->resolve(T::date('2026-03-08'), T::time('03:00'), $zone));
        // 1er novembre 2026 : 02:00 redevient 01:00 (UTC-2 → UTC-3).
        self::assertEquals(
            [T::utc('2026-11-01 03:30'), T::utc('2026-11-01 04:30')],
            $this->resolver->candidates(T::date('2026-11-01'), T::time('01:30'), $zone),
        );
    }

    #[DataProvider('fixedOffsets')]
    public function testZonesWithoutDaylightSavingTimeNeverHaveGapsOrRepeats(string $zone, string $local, string $expectedUtc): void
    {
        foreach (['2026-03-29', '2026-10-25', '2026-03-08', '2026-11-01'] as $day) {
            self::assertCount(1, $this->resolver->candidates(T::date($day), T::time('02:30'), T::zone($zone)), $zone.' '.$day);
        }
        self::assertEquals(T::utc($expectedUtc), $this->resolver->resolve(T::date('2026-06-10'), T::time($local), T::zone($zone)));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function fixedOffsets(): iterable
    {
        yield 'Guadeloupe UTC-4' => ['America/Guadeloupe', '08:00', '2026-06-10 12:00'];
        yield 'Martinique UTC-4' => ['America/Martinique', '08:00', '2026-06-10 12:00'];
        yield 'Guyane UTC-3' => ['America/Cayenne', '08:00', '2026-06-10 11:00'];
        yield 'La Réunion UTC+4' => ['Indian/Reunion', '08:00', '2026-06-10 04:00'];
        yield 'Mayotte UTC+3' => ['Indian/Mayotte', '08:00', '2026-06-10 05:00'];
        yield 'Saint-Barthélemy UTC-4' => ['America/St_Barthelemy', '08:00', '2026-06-10 12:00'];
        yield 'Saint-Martin UTC-4' => ['America/Marigot', '08:00', '2026-06-10 12:00'];
        yield 'Wallis UTC+12' => ['Pacific/Wallis', '08:00', '2026-06-09 20:00'];
        yield 'Tahiti UTC-10' => ['Pacific/Tahiti', '08:00', '2026-06-10 18:00'];
        yield 'Marquises UTC-9:30' => ['Pacific/Marquesas', '08:00', '2026-06-10 17:30'];
        yield 'Gambier UTC-9' => ['Pacific/Gambier', '08:00', '2026-06-10 17:00'];
        yield 'Nouvelle-Calédonie UTC+11' => ['Pacific/Noumea', '08:00', '2026-06-09 21:00'];
    }
}
