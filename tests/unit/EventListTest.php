<?php
declare(strict_types=1);

use MuseumCalendar\Calendar\EventList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventListTest extends TestCase
{
    private const TOUR = ['title' => 'ツアー', 'time' => '11:00–12:00', 'all_day' => false, 'start' => '11:00', 'end' => '12:00'];

    public function testCompleteEventsPassThroughInOrder(): void
    {
        $closed = ['title' => '休館', 'time' => '終日', 'all_day' => true, 'start' => '', 'end' => ''];
        $events = ['2026-11-16' => [self::TOUR, $closed], '2026-11-02' => [$closed]];
        self::assertSame($events, EventList::fromFilter($events));
    }

    public function testMissingOptionalKeysGetDefaults(): void
    {
        self::assertSame(
            ['2026-11-15' => [['title' => '題名のみ', 'time' => '', 'all_day' => false, 'start' => '', 'end' => '']]],
            EventList::fromFilter(['2026-11-15' => [['title' => '題名のみ']]]),
        );
    }

    public function testExtraKeysArePreserved(): void
    {
        $event = self::TOUR + ['category' => 'tour', 'url' => ['a' => 1]];
        self::assertSame(['2026-11-15' => [$event]], EventList::fromFilter(['2026-11-15' => [$event]]));
    }

    public function testDaysWithoutEventsAreDropped(): void
    {
        self::assertSame(
            ['2026-11-16' => [self::TOUR]],
            EventList::fromFilter(['2026-11-15' => [], '2026-11-16' => [self::TOUR], '2026-11-17' => []]),
        );
        self::assertSame([], EventList::fromFilter(['2026-11-15' => []]));
        self::assertSame([], EventList::fromFilter([]));
    }

    public function testDayIsReindexedAfterEventsWereRemoved(): void
    {
        $closed = ['title' => '休館', 'time' => '終日', 'all_day' => true, 'start' => '', 'end' => ''];
        $day = array_filter([$closed, self::TOUR, $closed, self::TOUR], static fn (array $event): bool => $event['title'] !== '休館');
        self::assertSame(['2026-11-15' => [self::TOUR, self::TOUR]], EventList::fromFilter(['2026-11-15' => $day]));
    }

    public function testClassifyAddsTheKindOfEachTitleAndReplacesOneFromAFilter(): void
    {
        $tea = ['title' => ' お茶室営業日 ', 'kind' => 'wedding'] + self::TOUR;
        self::assertSame(
            ['2026-11-15' => [self::TOUR + ['kind' => 'other'], array_replace($tea, ['kind' => 'tea'])]],
            EventList::classify(['2026-11-15' => [self::TOUR, $tea]]),
        );
    }

    #[DataProvider('days')]
    public function testClassifyHidesOpenOnADayWithClose(array $titles, array $shown): void
    {
        $day = array_map(static fn (string $title): array => ['title' => $title] + self::TOUR, $titles);
        self::assertSame($shown, array_column(EventList::classify(['2026-11-15' => $day])['2026-11-15'], 'title'));
    }

    public static function days(): array
    {
        return [
            'only OPEN' => [['OPEN', 'ガイドツアー開催日'], ['OPEN', 'ガイドツアー開催日']],
            'only CLOSE' => [['CLOSE'], ['CLOSE']],
            'OPEN before CLOSE' => [['OPEN', 'ガイドツアー開催日', 'CLOSE'], ['ガイドツアー開催日', 'CLOSE']],
            'CLOSE before two OPEN' => [['CLOSE', 'OPEN', '結婚式', ' OPEN '], ['CLOSE', '結婚式']],
            'neither' => [['ガイドツアー開催日', '臨時休館'], ['ガイドツアー開催日', '臨時休館']],
            'titles outside the table' => [['open', 'CLOSED', '臨時休館'], ['open', 'CLOSED', '臨時休館']],
        ];
    }

    public function testClassifyLeavesOtherDaysAlone(): void
    {
        $days = EventList::classify([
            '2026-11-14' => [['title' => 'OPEN'] + self::TOUR],
            '2026-11-15' => [['title' => 'CLOSE'] + self::TOUR],
        ]);
        self::assertSame([['OPEN'], ['CLOSE']], array_map(static fn (array $day): array => array_column($day, 'title'), array_values($days)));
    }

    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValue(mixed $value): void
    {
        $this->expectException(UnexpectedValueException::class);
        EventList::fromFilter($value);
    }

    public static function invalidValues(): array
    {
        $day = static fn (array $event): array => [['2026-11-15' => [$event]]];
        return [
            'null' => [null],
            'false' => [false],
            'string' => ['2026-11-15'],
            'object' => [new ArrayObject(['2026-11-15' => [self::TOUR]])],
            'list instead of dates' => [[[self::TOUR]]],
            'month key' => [['2026-11' => [self::TOUR]]],
            'unpadded date key' => [['2026-11-5' => [self::TOUR]]],
            'date key with trailing newline' => [["2026-11-15\n" => [self::TOUR]]],
            'day is a string' => [['2026-11-15' => 'ツアー']],
            'day is null' => [['2026-11-15' => null]],
            'day is a single event' => [['2026-11-15' => self::TOUR]],
            'event is a string' => [['2026-11-15' => ['ツアー']]],
            'title is missing' => $day(['time' => '終日', 'all_day' => true]),
            'title is not a string' => $day(['title' => 123]),
            'title is null' => $day(['title' => null]),
            'time is not a string' => $day(['title' => 'ツアー', 'time' => 1100]),
            'time is null' => $day(['title' => 'ツアー', 'time' => null]),
            'all_day is not a bool' => $day(['title' => 'ツアー', 'all_day' => 1]),
            'start is not a string' => $day(['title' => 'ツアー', 'start' => 11]),
            'end is not a string' => $day(['title' => 'ツアー', 'end' => false]),
            'one invalid event among valid ones' => [['2026-11-15' => [self::TOUR], '2026-11-16' => [self::TOUR, ['time' => '終日']]]],
        ];
    }
}
