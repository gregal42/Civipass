<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Event\Application\Read\ActionAvailability;
use App\Event\Application\Read\EventAction;
use App\Event\Application\Read\EventListItem;
use App\Event\Application\Read\EventListPage;
use App\Event\Application\Read\EventListQuery;
use App\Event\Domain\EventStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventListQueryTest extends TestCase
{
    public function testDefaults(): void
    {
        $query = new EventListQuery();

        self::assertNull($query->search);
        self::assertNull($query->status);
        self::assertNull($query->typeCode);
        self::assertSame('desc', $query->direction);
        self::assertSame(1, $query->page);
        self::assertSame(EventListQuery::DEFAULT_PER_PAGE, $query->perPage);
        self::assertFalse($query->hasFilters());
    }

    public function testSearchIsTrimmedBlankMeansNoSearchAndLengthIsBounded(): void
    {
        self::assertSame('Berle', (new EventListQuery('  Berle  '))->search);
        self::assertNull((new EventListQuery("   \t "))->search);
        self::assertSame(EventListQuery::SEARCH_MAX_LENGTH, mb_strlen((string) (new EventListQuery(str_repeat('é', 500)))->search));
        self::assertTrue((new EventListQuery('x'))->hasFilters());
        self::assertTrue((new EventListQuery(status: EventStatus::Closed))->hasFilters());
        self::assertTrue((new EventListQuery(typeCode: 'fire'))->hasFilters());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function directions(): iterable
    {
        yield 'croissant' => ['asc', 'asc'];
        yield 'décroissant' => ['desc', 'desc'];
        yield 'inconnu' => ['DROP TABLE', 'desc'];
        yield 'vide' => ['', 'desc'];
        yield 'casse' => ['ASC', 'desc'];
    }

    #[DataProvider('directions')]
    public function testOnlyTheTwoKnownDirectionsAreKept(string $given, string $expected): void
    {
        self::assertSame($expected, (new EventListQuery(direction: $given))->direction);
    }

    public function testPageAndPageSizeAreBounded(): void
    {
        self::assertSame(1, (new EventListQuery(page: -4))->page);
        self::assertSame(1, (new EventListQuery(page: 0))->page);
        self::assertSame(7, (new EventListQuery(page: 7))->page);
        self::assertSame(1, (new EventListQuery(perPage: 0))->perPage);
        self::assertSame(EventListQuery::MAX_PER_PAGE, (new EventListQuery(perPage: 100000))->perPage);
    }

    public function testPageArithmetic(): void
    {
        $empty = new EventListPage([], 0, 5, 20);
        self::assertSame([1, 1, 0, 0], [$empty->pages, $empty->page, $empty->from(), $empty->to()]);

        $last = new EventListPage(self::items(6), 26, 99, 20); // page demandée hors limites : dernière page
        self::assertSame([2, 2, 21, 26], [$last->pages, $last->page, $last->from(), $last->to()]);

        $first = new EventListPage(self::items(20), 26, 1, 20);
        self::assertSame([1, 20], [$first->from(), $first->to()]);
        self::assertSame(1, (new EventListPage([], 20, 1, 20))->pages);
        self::assertSame(2, (new EventListPage([], 21, 1, 20))->pages);
    }

    public function testTheCreateAvailabilityIsCarriedWithoutChangingThePage(): void
    {
        $page = (new EventListPage([], 3, 1, 20))->withCreate(new ActionAvailability(EventAction::Create, true));

        self::assertTrue($page->create?->allowed);
        self::assertSame(3, $page->total);
    }

    /**
     * @return list<EventListItem>
     */
    private static function items(int $count): array
    {
        return array_map(
            static fn (int $i): EventListItem => new EventListItem('e'.$i, 'EVT-2026-'.$i, 'Événement '.$i, EventStatus::Draft, new \DateTimeImmutable('2026-12-01'), null, 'fire', 'Incendie', 'o', 'Organisatrice', null, 'Europe/Paris'),
            range(1, $count),
        );
    }
}
