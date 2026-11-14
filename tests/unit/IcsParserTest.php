<?php
declare(strict_types=1);

use MuseumCalendar\Calendar\Month;
use MuseumCalendar\Ics\Parser;
use PHPUnit\Framework\TestCase;

final class IcsParserTest extends TestCase
{
    private function fixture(string $month = '2026-11'): array
    {
        return (new Parser())->forMonth(file_get_contents(dirname(__DIR__) . '/fixtures/museum.ics'), new Month($month));
    }

    private function titles(array $events, string $date): array
    {
        return array_column($events[$date] ?? [], 'title');
    }

    private function parse(string $events, string $month = '2026-11', string $zone = 'Asia/Tokyo'): array
    {
        return (new Parser())->forMonth("BEGIN:VCALENDAR\nVERSION:2.0\nX-WR-TIMEZONE:$zone\n$events\nEND:VCALENDAR", new Month($month));
    }

    public function testWeeklyExceptionsAndCancelledInstances(): void
    {
        $events = $this->fixture();
        self::assertContains('週替わりガイド', $this->titles($events, '2026-11-02'));
        self::assertNotContains('週替わりガイド', $this->titles($events, '2026-11-09'));
        self::assertNotContains('週替わりガイド', $this->titles($events, '2026-11-16'));
        self::assertSame([['title' => '日時変更ガイド', 'time' => '14:00–15:00']], $events['2026-11-17']);
        self::assertNotContains('週替わりガイド', $this->titles($events, '2026-11-23'));
        self::assertContains('週替わりガイド', $this->titles($events, '2026-11-30'));
    }

    public function testAllDayEndIsExclusiveAndMissingEndMeansOneDay(): void
    {
        $events = $this->fixture();
        foreach (['05', '06', '07'] as $day) {
            self::assertContains(['title' => '終日展示', 'time' => '終日'], $events['2026-11-' . $day]);
        }
        self::assertNotContains('終日展示', $this->titles($events, '2026-11-08'));
        self::assertContains(['title' => '一日休館', 'time' => '終日'], $events['2026-11-10']);
        self::assertNotContains('一日休館', $this->titles($events, '2026-11-11'));
    }

    public function testRecurringAllDayCountAndMonthBoundary(): void
    {
        $events = $this->fixture();
        foreach (['06', '13', '20'] as $day) {
            self::assertContains(['title' => '終日繰り返し', 'time' => '終日'], $events['2026-11-' . $day]);
        }
        self::assertNotContains('終日繰り返し', $this->titles($events, '2026-11-27'));
        self::assertContains('連日開催', $this->titles($events, '2026-11-01'));
        self::assertContains('連日開催', $this->titles($events, '2026-11-02'));
        self::assertNotContains('連日開催', $this->titles($events, '2026-11-03'));
    }

    public function testMonthlyRuleDurationAndAdditionalDates(): void
    {
        $events = $this->fixture();
        self::assertContains(['title' => '第2火曜講座', 'time' => '13:00–15:00'], $events['2026-11-10']);
        foreach (['04', '12', '19'] as $day) {
            self::assertContains(['title' => '追加開催', 'time' => '12:00–13:00'], $events['2026-11-' . $day]);
        }
    }

    public function testUtcAndMidnightEndArePlacedOnCorrectTokyoDates(): void
    {
        $november = $this->fixture();
        $december = $this->fixture('2026-12');
        self::assertContains(['title' => '月初深夜', 'time' => '00:00–01:00'], $november['2026-11-01']);
        self::assertNotContains('翌月の予定', $this->titles($november, '2026-11-30'));
        self::assertContains(['title' => '翌月の予定', 'time' => '00:30–01:30'], $december['2026-12-01']);
        self::assertContains(['title' => '月末夜間', 'time' => '23:00–24:00'], $november['2026-11-30']);
        self::assertNotContains('月末夜間', $this->titles($december, '2026-12-01'));
    }

    public function testCancelledSeriesDoesNotAppear(): void
    {
        foreach ($this->fixture() as $events) {
            self::assertNotContains('中止した連日開催', array_column($events, 'title'));
        }
    }

    public function testFloatingTimesRespectCalendarTimezoneAndDst(): void
    {
        $events = $this->parse("BEGIN:VEVENT\nUID:dst\nDTSTART:20261026T100000\nDTEND:20261026T110000\nRRULE:FREQ=WEEKLY;COUNT=3\nSUMMARY:DST\nEND:VEVENT", '2026-11', 'America/New_York');
        self::assertSame([['title' => 'DST', 'time' => '00:00–01:00']], $events['2026-11-03']);
        self::assertSame([['title' => 'DST', 'time' => '00:00–01:00']], $events['2026-11-10']);
        self::assertArrayNotHasKey('2026-11-02', $events);
    }

    public function testAllDayDateDoesNotShiftWithCalendarTimezone(): void
    {
        $events = $this->parse("BEGIN:VEVENT\nUID:allday\nDTSTART;VALUE=DATE:20261101\nSUMMARY:日付\nEND:VEVENT", '2026-11', 'America/New_York');
        self::assertSame(['2026-11-01' => [['title' => '日付', 'time' => '終日']]], $events);
    }

    public function testStartOnlyEventAtMonthBoundaryIsIncluded(): void
    {
        $events = $this->parse("BEGIN:VEVENT\nUID:point\nDTSTART:20261031T150000Z\nSUMMARY:開始のみ\nEND:VEVENT");
        self::assertSame(['2026-11-01' => [['title' => '開始のみ', 'time' => '00:00']]], $events);
    }

    public function testMultidayEventsAreClippedToRequestedMonth(): void
    {
        $events = $this->parse("BEGIN:VEVENT\nUID:span\nDTSTART;VALUE=DATE:20261029\nDTEND;VALUE=DATE:20261103\nSUMMARY:月またぎ\nEND:VEVENT");
        self::assertSame(['2026-11-01', '2026-11-02'], array_keys($events));
    }

    public function testEmptyCalendarIsValid(): void
    {
        self::assertSame([], $this->parse(''));
    }

    public function testRejectsHtmlInsteadOfIcs(): void
    {
        $this->expectException(RuntimeException::class);
        (new Parser())->validate('<html>Login required</html>');
    }

    public function testRejectsTruncatedCalendar(): void
    {
        $this->expectException(RuntimeException::class);
        (new Parser())->validate("BEGIN:VCALENDAR\nVERSION:2.0");
    }

    public function testRejectsHighFrequencyRecurrence(): void
    {
        $this->expectException(RuntimeException::class);
        $this->parse("BEGIN:VEVENT\nUID:rapid\nDTSTART:20261101T000000Z\nRRULE:FREQ=SECONDLY\nEND:VEVENT");
    }
}
