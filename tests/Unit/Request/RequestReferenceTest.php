<?php

declare(strict_types=1);

namespace App\Tests\Unit\Request;

use App\Event\Domain\Time\EventTimezone;
use App\Request\Domain\RequestReference;
use App\Shared\Domain\DomainRuleViolation;
use PHPUnit\Framework\TestCase;

final class RequestReferenceTest extends TestCase
{
    public function testFormatHasFourDigitsMinimumAndExtends(): void
    {
        self::assertSame('DEM-2026-0001', RequestReference::of(2026, 1)->value);
        self::assertSame('DEM-2026-9999', RequestReference::of(2026, 9999)->value);
        self::assertSame('DEM-2026-10000', RequestReference::of(2026, 10000)->value);
    }

    public function testRoundTripThroughString(): void
    {
        $reference = RequestReference::fromString('DEM-2027-12345');

        self::assertSame(2027, $reference->year());
        self::assertSame(12345, $reference->number());
        self::assertSame('DEM-2027-12345', (string) $reference);
    }

    public function testRejectsInvalidValues(): void
    {
        foreach (['LP-2026-0001', 'DEM-26-0001', 'DEM-2026-001', 'dem-2026-0001', ''] as $value) {
            try {
                RequestReference::fromString($value);
                self::fail($value);
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(DomainRuleViolation::class);
        RequestReference::of(2026, 0);
    }

    public function testYearIsTheCalendarYearInTheEventTimezone(): void
    {
        // 31 décembre 2026, 23:30 UTC : déjà 2027 à Paris (UTC+1) et à Tahiti encore 2026 (UTC-10).
        $at = new \DateTimeImmutable('2026-12-31 23:30:00', new \DateTimeZone('UTC'));

        self::assertSame(2027, RequestReference::yearOf($at, EventTimezone::fromIdentifier('Europe/Paris')));
        self::assertSame(2026, RequestReference::yearOf($at, EventTimezone::fromIdentifier('Pacific/Tahiti')));
    }
}
