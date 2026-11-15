<?php
declare(strict_types=1);

use MuseumCalendar\Calendar\DemoSource;
use MuseumCalendar\Calendar\Month;
use PHPUnit\Framework\TestCase;

final class DemoSourceTest extends TestCase
{
    private const CLOSED = ['title' => 'サンプル休館', 'time' => '終日'];
    private const OPEN = ['title' => 'サンプル開館', 'time' => '10:30–16:30'];
    private const TOUR = ['title' => 'サンプルツアー', 'time' => '11:00–12:00'];
    private const TEA_ROOM = ['title' => 'サンプル茶室', 'time' => '12:00–16:00'];

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
                self::assertStringStartsWith('サンプル', $event['title']);
            }
        }
    }
}
