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

    private function allDay(string $title): array
    {
        return ['title' => $title, 'time' => '終日', 'all_day' => true, 'start' => '', 'end' => ''];
    }

    private function timed(string $title, string $start, string $end = ''): array
    {
        return ['title' => $title, 'time' => $end === '' ? $start : "{$start}–{$end}", 'all_day' => false, 'start' => $start, 'end' => $end];
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
        self::assertSame([$this->timed('日時変更ガイド', '14:00', '15:00')], $events['2026-11-17']);
        self::assertNotContains('週替わりガイド', $this->titles($events, '2026-11-23'));
        self::assertContains('週替わりガイド', $this->titles($events, '2026-11-30'));
    }

    public function testAllDayEndIsExclusiveAndMissingEndMeansOneDay(): void
    {
        $events = $this->fixture();
        foreach (['05', '06', '07'] as $day) {
            self::assertContains($this->allDay('終日展示'), $events['2026-11-' . $day]);
        }
        self::assertNotContains('終日展示', $this->titles($events, '2026-11-08'));
        self::assertContains($this->allDay('一日休館'), $events['2026-11-10']);
        self::assertNotContains('一日休館', $this->titles($events, '2026-11-11'));
    }

    public function testRecurringAllDayCountAndMonthBoundary(): void
    {
        $events = $this->fixture();
        foreach (['06', '13', '20'] as $day) {
            self::assertContains($this->allDay('終日繰り返し'), $events['2026-11-' . $day]);
        }
        self::assertNotContains('終日繰り返し', $this->titles($events, '2026-11-27'));
        self::assertContains('連日開催', $this->titles($events, '2026-11-01'));
        self::assertContains('連日開催', $this->titles($events, '2026-11-02'));
        self::assertNotContains('連日開催', $this->titles($events, '2026-11-03'));
    }

    public function testMonthlyRuleDurationAndAdditionalDates(): void
    {
        $events = $this->fixture();
        self::assertContains($this->timed('第2火曜講座', '13:00', '15:00'), $events['2026-11-10']);
        foreach (['04', '12', '19'] as $day) {
            self::assertContains($this->timed('追加開催', '12:00', '13:00'), $events['2026-11-' . $day]);
        }
    }

    public function testUtcAndMidnightEndArePlacedOnCorrectTokyoDates(): void
    {
        $november = $this->fixture();
        $december = $this->fixture('2026-12');
        self::assertContains($this->timed('月初深夜', '00:00', '01:00'), $november['2026-11-01']);
        self::assertNotContains('翌月の予定', $this->titles($november, '2026-11-30'));
        self::assertContains($this->timed('翌月の予定', '00:30', '01:30'), $december['2026-12-01']);
        self::assertContains($this->timed('月末夜間', '23:00', '24:00'), $november['2026-11-30']);
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
        self::assertSame([$this->timed('DST', '00:00', '01:00')], $events['2026-11-03']);
        self::assertSame([$this->timed('DST', '00:00', '01:00')], $events['2026-11-10']);
        self::assertArrayNotHasKey('2026-11-02', $events);
    }

    public function testAllDayDateDoesNotShiftWithCalendarTimezone(): void
    {
        $events = $this->parse("BEGIN:VEVENT\nUID:allday\nDTSTART;VALUE=DATE:20261101\nSUMMARY:日付\nEND:VEVENT", '2026-11', 'America/New_York');
        self::assertSame(['2026-11-01' => [$this->allDay('日付')]], $events);
    }

    public function testStartOnlyEventAtMonthBoundaryIsIncluded(): void
    {
        $events = $this->parse("BEGIN:VEVENT\nUID:point\nDTSTART:20261031T150000Z\nSUMMARY:開始のみ\nEND:VEVENT");
        self::assertSame(['2026-11-01' => [$this->timed('開始のみ', '00:00')]], $events);
    }

    public function testMultidayEventsAreClippedToRequestedMonth(): void
    {
        $events = $this->parse("BEGIN:VEVENT\nUID:span\nDTSTART;VALUE=DATE:20261029\nDTEND;VALUE=DATE:20261103\nSUMMARY:月またぎ\nEND:VEVENT");
        self::assertSame(['2026-11-01', '2026-11-02'], array_keys($events));
    }

    public function testEachDayCarriesItsOwnPartOfTheEvent(): void
    {
        $events = $this->parse(implode("\n", [
            "BEGIN:VEVENT\nUID:overnight\nDTSTART:20261110T130000Z\nDTEND:20261111T170000Z\nSUMMARY:夜間作業\nEND:VEVENT",
            "BEGIN:VEVENT\nUID:exhibition\nDTSTART;VALUE=DATE:20261120\nDTEND;VALUE=DATE:20261122\nSUMMARY:特別展\nEND:VEVENT",
            "BEGIN:VEVENT\nUID:deadline\nDTSTART:20261125T080000Z\nSUMMARY:締切\nEND:VEVENT",
            "BEGIN:VEVENT\nUID:talk\nDTSTART:20261125T010000Z\nDTEND:20261125T023000Z\nSUMMARY:講演\nEND:VEVENT",
        ]));
        $exhibition = ['title' => '特別展', 'time' => '終日', 'all_day' => true, 'start' => '', 'end' => ''];
        self::assertSame([
            '2026-11-10' => [['title' => '夜間作業', 'time' => '22:00–24:00', 'all_day' => false, 'start' => '22:00', 'end' => '24:00']],
            '2026-11-11' => [['title' => '夜間作業', 'time' => '00:00–24:00', 'all_day' => false, 'start' => '00:00', 'end' => '24:00']],
            '2026-11-12' => [['title' => '夜間作業', 'time' => '00:00–02:00', 'all_day' => false, 'start' => '00:00', 'end' => '02:00']],
            '2026-11-20' => [$exhibition],
            '2026-11-21' => [$exhibition],
            '2026-11-25' => [
                ['title' => '講演', 'time' => '10:00–11:30', 'all_day' => false, 'start' => '10:00', 'end' => '11:30'],
                ['title' => '締切', 'time' => '17:00', 'all_day' => false, 'start' => '17:00', 'end' => ''],
            ],
        ], $events);
    }

    public function testEventWithoutSummaryIsUntitled(): void
    {
        $events = $this->parse("BEGIN:VEVENT\nUID:untitled\nDTSTART;VALUE=DATE:20261110\nEND:VEVENT");
        self::assertSame(['2026-11-10' => [$this->allDay('（無題）')]], $events);
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
