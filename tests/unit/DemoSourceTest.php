<?php
declare(strict_types=1);

use MuseumCalendar\Calendar\DemoSource;
use MuseumCalendar\Calendar\Month;
use PHPUnit\Framework\TestCase;

final class DemoSourceTest extends TestCase
{
    private const CLOSED = ['title' => '休館（サンプル）', 'time' => '終日', 'all_day' => true, 'start' => '', 'end' => ''];
    private const OPEN = ['title' => '開館（サンプル）', 'time' => '10:30–16:30', 'all_day' => false, 'start' => '10:30', 'end' => '16:30'];
    private const TOUR = ['title' => 'ツアー（サンプル）', 'time' => '11:00–12:00', 'all_day' => false, 'start' => '11:00', 'end' => '12:00'];
    private const TEA_ROOM = ['title' => '茶室（サンプル）', 'time' => '12:00–16:00', 'all_day' => false, 'start' => '12:00', 'end' => '16:00'];

    public function testEachWeekdayHasItsSampleEvents(): void
    {
        $events = (new DemoSource())->forMonth(new Month('2026-11'));
        self::assertSame([self::CLOSED], $events['2026-11-02'], 'Monday');
        foreach (['2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'] as $weekday) {
            self::assertSame([self::OPEN, self::TOUR], $events[$weekday], $weekday);
        }
        self::assertSame([self::OPEN, self::TEA_ROOM], $events['2026-11-07'], 'Saturday');
        self::assertSame([self::OPEN, self::TEA_ROOM], $events['2026-11-08'], 'Sunday');
    }

    public function testCoversEveryDayOfTheMonthAndNoOther(): void
    {
        $events = (new DemoSource())->forMonth(new Month('2024-02'));
        self::assertCount(29, $events);
        self::assertSame([self::OPEN, self::TOUR], $events['2024-02-29'], 'Leap day is a Thursday');
        self::assertSame($events, (new DemoSource())->forMonth(new Month('2024-02')));
    }

    public function testEveryTitleIsMarkedAsSample(): void
    {
        foreach ((new DemoSource())->forMonth(new Month('2026-11')) as $day) {
            foreach ($day as $event) {
                self::assertStringContainsString('サンプル', $event['title']);
            }
        }
    }
}
