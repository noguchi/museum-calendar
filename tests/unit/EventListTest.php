<?php
declare(strict_types=1);

use MuseumCalendar\Calendar\EventList;
use MuseumCalendar\Calendar\KindTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventListTest extends TestCase
{
    private const TOUR = ['title' => 'ツアー', 'time' => '11:00–12:00', 'all_day' => false, 'start' => '11:00', 'end' => '12:00', 'description' => '集合：玄関前', 'url' => 'https://example.com/tour'];

    public function testClassifyAddsTheKindOfEachTitle(): void
    {
        $tea = ['title' => ' お茶室営業日 '] + self::TOUR;
        self::assertSame(
            ['2026-11-15' => [self::TOUR + ['kind' => 'other', 'color' => ''], $tea + ['kind' => 'tea', 'color' => '#e8f5e9']]],
            EventList::classify(['2026-11-15' => [self::TOUR, $tea]], KindTable::defaults()),
        );
    }

    #[DataProvider('days')]
    public function testClassifyHidesOpenOnADayWithClose(array $titles, array $shown): void
    {
        $day = array_map(static fn (string $title): array => ['title' => $title] + self::TOUR, $titles);
        self::assertSame($shown, array_column(EventList::classify(['2026-11-15' => $day], KindTable::defaults())['2026-11-15'], 'title'));
    }

    public static function days(): array
    {
        return [
            'only open' => [['開館日', 'ガイドツアー開催日'], ['開館日', 'ガイドツアー開催日']],
            'only closed' => [['休館日'], ['休館日']],
            'open before closed' => [['開館日', 'ガイドツアー開催日', '休館日'], ['ガイドツアー開催日', '休館日']],
            'closed before two open' => [['休館日', '開館日', '結婚式', ' 開館日 '], ['休館日', '結婚式']],
            'neither' => [['ガイドツアー開催日', '臨時休館'], ['ガイドツアー開催日', '臨時休館']],
            'titles outside the table' => [['open', 'CLOSED', '臨時休館'], ['open', 'CLOSED', '臨時休館']],
            'English texts and short forms outside the table' => [['OPEN', 'CLOSE', '休館'], ['OPEN', 'CLOSE', '休館']],
        ];
    }

    public function testClassifyUsesTheGivenTable(): void
    {
        $kinds = KindTable::fromRows([
            ...KindTable::DEFAULTS,
            ['title' => '臨時休館', 'kind' => 'closed', 'english' => 'Closed', 'color' => ''],
            ['title' => 'ワークショップ', 'kind' => 'workshop', 'english' => 'Workshop', 'color' => ''],
        ]);
        $day = array_map(static fn (string $title): array => ['title' => $title] + self::TOUR, ['開館日', 'ワークショップ', ' 臨時休館 ']);
        $classified = EventList::classify(['2026-11-15' => $day], $kinds)['2026-11-15'];
        self::assertSame(['ワークショップ', ' 臨時休館 '], array_column($classified, 'title'));
        self::assertSame(['workshop', 'closed'], array_column($classified, 'kind'));
        $labelled = array_map(static fn (array $event): array => $event + ['label' => 'label of ' . $event['title'], 'label_lang' => ''], $classified);
        self::assertSame(['kind' => 'closed', 'color' => '', 'label' => 'label of  臨時休館 ', 'label_lang' => '', 'hours' => ''], EventList::status($labelled));
    }

    public function testClassifyAddsTheColorOfEachTitle(): void
    {
        $kinds = KindTable::fromRows([
            ['title' => '休館日', 'kind' => 'closed', 'english' => '', 'color' => '#E26B6B'],
            ['title' => 'ワークショップ', 'kind' => 'workshop', 'english' => '', 'color' => '#2f6f4f'],
            ['title' => '講座', 'kind' => 'lecture', 'english' => '', 'color' => ''],
        ]);
        $day = array_map(static fn (string $title): array => ['title' => $title] + self::TOUR, [' ワークショップ ', '講座', '臨時休館', '休館日']);
        $classified = EventList::classify(['2026-11-15' => $day], $kinds)['2026-11-15'];
        self::assertSame(['#2f6f4f', '', '', '#e26b6b'], array_column($classified, 'color'));
        $labelled = array_map(static fn (array $event): array => $event + ['label' => $event['title'], 'label_lang' => ''], $classified);
        self::assertSame(['kind' => 'closed', 'color' => '#e26b6b', 'label' => '休館日', 'label_lang' => '', 'hours' => ''], EventList::status($labelled));
    }

    public function testClassifyWithAnEmptyTableMakesEveryEventOther(): void
    {
        $day = array_map(static fn (string $title): array => ['title' => $title] + self::TOUR, ['開館日', '休館日']);
        $classified = EventList::classify(['2026-11-15' => $day], KindTable::fromRows([]))['2026-11-15'];
        self::assertSame(['other', 'other'], array_column($classified, 'kind'));
    }

    public function testClassifyLeavesOtherDaysAlone(): void
    {
        $days = EventList::classify([
            '2026-11-14' => [['title' => '開館日'] + self::TOUR],
            '2026-11-15' => [['title' => '休館日'] + self::TOUR],
        ], KindTable::defaults());
        self::assertSame([['開館日'], ['休館日']], array_map(static fn (array $day): array => array_column($day, 'title'), array_values($days)));
    }

    #[DataProvider('statuses')]
    public function testStatusOfADay(array $titles, ?array $status): void
    {
        $events = [
            'OPEN' => ['title' => '開館日', 'time' => '10:30–16:30', 'all_day' => false, 'start' => '10:30', 'end' => '16:30'],
            'late OPEN' => ['title' => '開館日', 'time' => '13:00–20:00', 'all_day' => false, 'start' => '13:00', 'end' => '20:00'],
            'all-day OPEN' => ['title' => '開館日', 'time' => 'All day', 'all_day' => true, 'start' => '', 'end' => ''],
            'CLOSE' => ['title' => '休館日', 'time' => 'All day', 'all_day' => true, 'start' => '', 'end' => ''],
            'timed CLOSE' => ['title' => '休館日', 'time' => '10:30–16:30', 'all_day' => false, 'start' => '10:30', 'end' => '16:30'],
            'tour' => ['title' => 'ガイドツアー開催日'] + self::TOUR,
        ];
        $day = EventList::classify(['2026-11-15' => array_map(static fn (string $title): array => $events[$title], $titles)], KindTable::defaults())['2026-11-15'];
        $labelled = array_map(static fn (array $event): array => $event + ['label' => 'label of ' . $event['title'], 'label_lang' => ''], $day);
        self::assertSame($status, EventList::status($labelled));
    }

    public static function statuses(): array
    {
        return [
            'only OPEN' => [['tour', 'OPEN'], ['kind' => 'open', 'color' => '#ffebee', 'label' => 'label of 開館日', 'label_lang' => '', 'hours' => '10:30–16:30']],
            'all-day OPEN' => [['all-day OPEN', 'tour'], ['kind' => 'open', 'color' => '#ffebee', 'label' => 'label of 開館日', 'label_lang' => '', 'hours' => '']],
            'two OPEN' => [['late OPEN', 'OPEN'], ['kind' => 'open', 'color' => '#ffebee', 'label' => 'label of 開館日', 'label_lang' => '', 'hours' => '13:00–20:00']],
            'only CLOSE' => [['CLOSE'], ['kind' => 'closed', 'color' => '#f5f5f5', 'label' => 'label of 休館日', 'label_lang' => '', 'hours' => '']],
            'timed CLOSE' => [['timed CLOSE'], ['kind' => 'closed', 'color' => '#f5f5f5', 'label' => 'label of 休館日', 'label_lang' => '', 'hours' => '']],
            'OPEN and CLOSE' => [['OPEN', 'tour', 'CLOSE'], ['kind' => 'closed', 'color' => '#f5f5f5', 'label' => 'label of 休館日', 'label_lang' => '', 'hours' => '']],
            'neither' => [['tour'], null],
            'no events' => [[], null],
        ];
    }

    public function testStatusPrefersCloseOverAnEarlierOpen(): void
    {
        $open = ['title' => '開館日', 'label' => 'OPEN', 'label_lang' => '', 'kind' => 'open', 'color' => ''] + self::TOUR;
        $closed = ['title' => '休館日', 'label' => 'CLOSED', 'label_lang' => '', 'kind' => 'closed', 'color' => '', 'time' => 'All day', 'all_day' => true] + self::TOUR;
        self::assertSame(['kind' => 'closed', 'color' => '', 'label' => 'CLOSED', 'label_lang' => '', 'hours' => ''], EventList::status([$open, $closed]));
    }

    public function testStatusKeepsTheLanguageOfItsLabel(): void
    {
        $closed = ['title' => '臨時休館', 'label' => '臨時休館', 'label_lang' => 'ja', 'kind' => 'closed', 'color' => '', 'time' => 'All day', 'all_day' => true] + self::TOUR;
        $open = ['title' => '開館日', 'label' => 'OPEN', 'label_lang' => '', 'kind' => 'open', 'color' => ''] + self::TOUR;
        self::assertSame(['kind' => 'closed', 'color' => '', 'label' => '臨時休館', 'label_lang' => 'ja', 'hours' => ''], EventList::status([$open, $closed]));
        self::assertSame(['kind' => 'open', 'color' => '', 'label' => 'OPEN', 'label_lang' => '', 'hours' => '11:00–12:00'], EventList::status([$open]));
    }
}
