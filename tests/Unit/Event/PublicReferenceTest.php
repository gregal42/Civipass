<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Event\Domain\EventReferenceCounter;
use App\Event\Domain\PublicReference;
use App\Shared\Domain\DomainRuleViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicReferenceTest extends TestCase
{
    public function testFormatWithSixDigitsMinimum(): void
    {
        $at = new \DateTimeImmutable('2026-06-01 10:00:00', new \DateTimeZone('UTC'));

        self::assertSame('EVT-2026-000154', PublicReference::fromCounter(154, $at)->value);
        self::assertSame('EVT-2026-000001', (string) PublicReference::fromCounter(1, $at));
        self::assertSame('EVT-2026-999999', PublicReference::fromCounter(999999, $at)->value);
    }

    public function testNumberIsExtensibleBeyondSixDigits(): void
    {
        $at = new \DateTimeImmutable('2031-01-01 00:00:00', new \DateTimeZone('UTC'));

        $reference = PublicReference::fromCounter(1000000, $at);

        self::assertSame('EVT-2031-1000000', $reference->value);
        self::assertSame(1000000, $reference->number());
        self::assertSame(2031, $reference->year());
        self::assertTrue($reference->equals(PublicReference::fromString('EVT-2031-1000000')));
    }

    public function testYearIsTheUtcYearOfCreation(): void
    {
        // 1er janvier 00:30 à Paris = 31 décembre 23:30 UTC.
        $at = new \DateTimeImmutable('2027-01-01 00:30:00', new \DateTimeZone('Europe/Paris'));

        self::assertSame('EVT-2026-000010', PublicReference::fromCounter(10, $at)->value);
    }

    #[DataProvider('invalid')]
    public function testRefusesInvalidReferences(string $value): void
    {
        $this->expectException(DomainRuleViolation::class);
        PublicReference::fromString($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'préfixe' => ['EV-2026-000154'];
        yield 'année' => ['EVT-26-000154'];
        yield 'moins de six chiffres' => ['EVT-2026-154'];
        yield 'minuscules' => ['evt-2026-000154'];
        yield 'suffixe' => ['EVT-2026-000154x'];
        yield 'vide' => [''];
    }

    public function testRefusesNonPositiveNumber(): void
    {
        $this->expectException(DomainRuleViolation::class);
        PublicReference::fromCounter(0, new \DateTimeImmutable());
    }

    public function testCounterIsGlobalAndTheYearComesOnlyFromTheCreationDate(): void
    {
        $counter = new EventReferenceCounter();
        self::assertSame(EventReferenceCounter::SINGLE_ROW_ID, $counter->id());
        self::assertSame(0, $counter->lastNumber());

        // Le numéro vient du compteur global ; l'année, de la date de création seule : la séquence ne se remet jamais à zéro.
        $december = PublicReference::fromCounter(3, new \DateTimeImmutable('2026-12-31 12:00:00', new \DateTimeZone('UTC')));
        $january = PublicReference::fromCounter(4, new \DateTimeImmutable('2027-01-01 12:00:00', new \DateTimeZone('UTC')));

        self::assertSame('EVT-2026-000003', $december->value);
        self::assertSame('EVT-2027-000004', $january->value);
    }
}
