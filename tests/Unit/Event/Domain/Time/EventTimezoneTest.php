<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event\Domain\Time;

use App\Event\Domain\Time\EventTimezone;
use App\Shared\Domain\DomainRuleViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventTimezoneTest extends TestCase
{
    public function testEverySupportedIdentifierIsKnownByPhp(): void
    {
        self::assertCount(14, EventTimezone::SUPPORTED);
        foreach (EventTimezone::SUPPORTED as $identifier) {
            $zone = EventTimezone::fromIdentifier($identifier);
            self::assertSame($identifier, $zone->toDateTimeZone()->getName());
            self::assertSame($identifier, (string) $zone);
        }
    }

    #[DataProvider('unsupported')]
    public function testRefusesUnsupportedIdentifiers(string $identifier): void
    {
        $this->expectException(DomainRuleViolation::class);
        EventTimezone::fromIdentifier($identifier);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupported(): iterable
    {
        yield 'vide' => [''];
        yield 'casse' => ['europe/paris'];
        yield 'autre fuseau' => ['Europe/London'];
        yield 'décalage fixe' => ['+02:00'];
        yield 'abréviation' => ['CET'];
    }

    public function testEquality(): void
    {
        self::assertTrue(EventTimezone::fromIdentifier('Europe/Paris')->equals(EventTimezone::fromIdentifier('Europe/Paris')));
        self::assertFalse(EventTimezone::fromIdentifier('Europe/Paris')->equals(EventTimezone::fromIdentifier('Indian/Reunion')));
    }
}
