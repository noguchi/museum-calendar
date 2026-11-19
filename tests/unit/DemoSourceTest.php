<?php
declare(strict_types=1);

use MuseumCalendar\Calendar\DemoSource;
use MuseumCalendar\Calendar\Month;
use PHPUnit\Framework\TestCase;

final class DemoSourceTest extends TestCase
{
    private const CLOSED = ['title' => 'CLOSE', 'time' => '終日', 'all_day' => true, 'start' => '', 'end' => '', 'description' => ''];
    private const OPEN = ['title' => 'OPEN', 'time' => '10:30–16:30', 'all_day' => false, 'start' => '10:30', 'end' => '16:30', 'description' => ''];
    private const TOUR = [
        'title' => 'ガイドツアー開催日',
        'time' => '11:00–12:00',
        'all_day' => false,
        'start' => '11:00',
        'end' => '12:00',
        'description' => "集合場所：洋館玄関前\n所要時間：約60分",
    ];
    private const TEA_ROOM = ['title' => 'お茶室営業日', 'time' => '12:00–16:00', 'all_day' => false, 'start' => '12:00', 'end' => '16:00', 'description' => ''];

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

    public function testSamplesFollowTheWeekdayOfEachDate(): void
    {
        $samples = [
            1 => [self::CLOSED],
            2 => [self::OPEN, self::TOUR],
            3 => [self::OPEN, self::TOUR],
            4 => [self::OPEN, self::TOUR],
            5 => [self::OPEN, self::TOUR],
            6 => [self::OPEN, self::TEA_ROOM],
            7 => [self::OPEN, self::TEA_ROOM],
        ];
        $tokyo = new DateTimeZone('Asia/Tokyo');
        foreach (['2026-06', '2026-07', '2026-11'] as $month) {
            $events = (new DemoSource())->forMonth(new Month($month));
            self::assertCount((int) (new DateTimeImmutable($month . '-01', $tokyo))->format('t'), $events);
            foreach ($events as $date => $day) {
                self::assertSame($samples[(int) (new DateTimeImmutable($date, $tokyo))->format('N')], $day, $date);
            }
        }
    }

    public function testCoversEveryDayOfTheMonthAndNoOther(): void
    {
        $events = (new DemoSource())->forMonth(new Month('2024-02'));
        self::assertCount(29, $events);
        self::assertSame([self::OPEN, self::TOUR], $events['2024-02-29'], 'Leap day is a Thursday');
        self::assertSame($events, (new DemoSource())->forMonth(new Month('2024-02')));
    }
}
