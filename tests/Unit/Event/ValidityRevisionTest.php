<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Event\Application\Support\ValidityRevision;
use App\Event\Domain\Validity\ValidityMode;
use PHPUnit\Framework\TestCase;

final class ValidityRevisionTest extends TestCase
{
    public function testTheRevisionIgnoresTheOrderOfTheSlots(): void
    {
        self::assertSame(
            ValidityRevision::of(ValidityMode::SpecificPeriods, ['b', 'a', 'c']),
            ValidityRevision::of(ValidityMode::SpecificPeriods, ['c', 'a', 'b']),
        );
    }

    public function testTheRevisionChangesWithTheModeAndWithAnySlot(): void
    {
        $revisions = [
            ValidityRevision::of(ValidityMode::Unconfigured, []),
            ValidityRevision::of(ValidityMode::EventDuration, []),
            ValidityRevision::of(ValidityMode::SpecificPeriods, ['a']),
            ValidityRevision::of(ValidityMode::SpecificPeriods, ['a', 'b']),
            ValidityRevision::of(ValidityMode::SpecificPeriods, ['b']),
        ];

        self::assertCount(5, array_unique($revisions));
        self::assertSame(32, \strlen($revisions[0]));
    }

    public function testAnIterableIsAccepted(): void
    {
        self::assertSame(
            ValidityRevision::of(ValidityMode::SpecificPeriods, ['a', 'b']),
            ValidityRevision::of(ValidityMode::SpecificPeriods, new \ArrayIterator(['b', 'a'])),
        );
    }
}
