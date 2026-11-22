<?php
declare(strict_types=1);

use MuseumCalendar\Calendar\Month;
use MuseumCalendar\Ics\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
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
        return ['title' => $title, 'time' => '終日', 'all_day' => true, 'start' => '', 'end' => '', 'description' => '', 'url' => ''];
    }

    private function timed(string $title, string $start, string $end = ''): array
    {
        return ['title' => $title, 'time' => $end === '' ? $start : "{$start}–{$end}", 'all_day' => false, 'start' => $start, 'end' => $end, 'description' => '', 'url' => ''];
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
        $exhibition = $this->allDay('特別展');
        self::assertSame([
            '2026-11-10' => [$this->timed('夜間作業', '22:00', '24:00')],
            '2026-11-11' => [$this->timed('夜間作業', '00:00', '24:00')],
            '2026-11-12' => [$this->timed('夜間作業', '00:00', '02:00')],
            '2026-11-20' => [$exhibition],
            '2026-11-21' => [$exhibition],
            '2026-11-25' => [$this->timed('講演', '10:00', '11:30'), $this->timed('締切', '17:00')],
        ], $events);
    }

    public function testDescriptionIsPlainTextWithoutMarkup(): void
    {
        $events = $this->fixture();
        self::assertSame(
            "午後の演奏会\n曲目：A\nB\nC\nD\n雨天決行 & 予約不要\n定員20名",
            $events['2026-11-26'][0]['description'],
        );
        self::assertSame('https://example.com/concert', $events['2026-11-26'][0]['url']);
        self::assertSame("受付で名前をお伝えください。\n持ち物：筆記用具, 上履き; 飲み物", $events['2026-11-24'][0]['description']);
        self::assertSame(str_repeat('あいうえおかきくけこ', 50) . '…', $events['2026-11-28'][0]['description']);
        self::assertSame('', $events['2026-11-20'][0]['description']);
        self::assertSame(['', '', ''], [$events['2026-11-24'][0]['url'], $events['2026-11-28'][0]['url'], $events['2026-11-20'][0]['url']]);
    }

    public function testEventCarriesNoLocationAttendeeOrganizerOrAttachment(): void
    {
        $event = $this->fixture()['2026-11-24'][0];
        self::assertSame(['title', 'time', 'all_day', 'start', 'end', 'description', 'url'], array_keys($event));
        self::assertDoesNotMatchRegularExpression('/location-7f3a|attendee-91c2|organizer-5d8e|attachment-3b6f/', implode("\n", array_map('strval', $event)));
    }

    public function testEachOccurrenceAndDayCarriesTheDescription(): void
    {
        $events = $this->parse(implode("\n", [
            "BEGIN:VEVENT\nUID:series\nDTSTART;VALUE=DATE:20261102\nDTEND;VALUE=DATE:20261104\nRRULE:FREQ=WEEKLY;COUNT=2\nSUMMARY:展示\nDESCRIPTION:本館2階\nEND:VEVENT",
            "BEGIN:VEVENT\nUID:series\nRECURRENCE-ID;VALUE=DATE:20261109\nDTSTART;VALUE=DATE:20261109\nSUMMARY:展示\nDESCRIPTION:<b>別館</b>\nEND:VEVENT",
        ]));
        self::assertSame(
            ['2026-11-02' => ['本館2階'], '2026-11-03' => ['本館2階'], '2026-11-09' => ['別館']],
            array_map(static fn (array $day): array => array_column($day, 'description'), $events),
        );
    }

    public function testEachOccurrenceCarriesTheUrlOfItsSeriesOrItsOwn(): void
    {
        $events = $this->parse(implode("\n", [
            "BEGIN:VEVENT\nUID:series\nDTSTART;VALUE=DATE:20261102\nRRULE:FREQ=WEEKLY;COUNT=3\nSUMMARY:展示\nDESCRIPTION:本館2階\\nhttps://example.com/exhibition\nEND:VEVENT",
            "BEGIN:VEVENT\nUID:series\nRECURRENCE-ID;VALUE=DATE:20261109\nDTSTART;VALUE=DATE:20261109\nSUMMARY:展示\nDESCRIPTION:別館\\nhttps://example.com/annex\nEND:VEVENT",
        ]));
        self::assertSame(
            [
                '2026-11-02' => [['本館2階', 'https://example.com/exhibition']],
                '2026-11-09' => [['別館', 'https://example.com/annex']],
                '2026-11-16' => [['本館2階', 'https://example.com/exhibition']],
            ],
            array_map(static fn (array $day): array => array_map(static fn (array $event): array => [$event['description'], $event['url']], $day), $events),
        );
    }

    #[DataProvider('descriptions')]
    public function testDescriptionConversion(string $html, string $text): void
    {
        self::assertSame(['description' => $text, 'url' => ''], Parser::description($html));
    }

    #[DataProvider('links')]
    public function testLineWithOnlyAUrlBecomesTheUrl(string $html, string $text, string $url): void
    {
        self::assertSame(['description' => $text, 'url' => $url], Parser::description($html));
    }

    public static function links(): array
    {
        return [
            'a line with only a URL' => ["演奏会\nhttps://example.com/concert\n予約不要", "演奏会\n予約不要", 'https://example.com/concert'],
            'a link as Google stores it' => ['演奏会<br><a href="https://example.com/concert">https://example.com/concert</a><br>予約不要', "演奏会\n予約不要", 'https://example.com/concert'],
            'query and fragment' => ['https://example.com/a?b=1&amp;c=2#d', '', 'https://example.com/a?b=1&c=2#d'],
            'http and spaces around the line' => ["演奏会\n \u{3000}http://example.com/ \t", '演奏会', 'http://example.com/'],
            'blank lines left at both ends are trimmed' => ["https://example.com/\n\n演奏会\n\n", '演奏会', 'https://example.com/'],
            'blank lines inside stay' => ["演奏会\n\nhttps://example.com/\n\n予約不要", "演奏会\n\n\n予約不要", 'https://example.com/'],
            'only a URL' => ['https://example.com/', '', 'https://example.com/'],
            'the first of two wins and the second stays' => ["https://example.com/1\n演奏会\nhttps://example.com/2", "演奏会\nhttps://example.com/2", 'https://example.com/1'],
            'a URL inside a sentence stays' => ["詳細は https://example.com/ へ", '詳細は https://example.com/ へ', ''],
            'a URL past the cut still counts' => [str_repeat('あ', 600) . "\nhttps://example.com/", str_repeat('あ', 500) . '…', 'https://example.com/'],
            'javascript stays text' => ["javascript:alert(1)\n演奏会", "javascript:alert(1)\n演奏会", ''],
            'ftp stays text' => ['ftp://example.com/file', 'ftp://example.com/file', ''],
            'no host stays text' => ["https://\nhttps:///path\nhttp:example.com", "https://\nhttps:///path\nhttp:example.com", ''],
            'an invalid candidate stays and a later valid one wins' => ["https:///path\nhttps://example.com/", 'https:///path', 'https://example.com/'],
        ];
    }

    public static function descriptions(): array
    {
        return [
            'empty' => ['', ''],
            'plain text' => ['ガイドツアー', 'ガイドツアー'],
            'inner newlines stay' => ["1行目\n\n2行目", "1行目\n\n2行目"],
            'CRLF and CR become LF' => ["a\r\nb\rc", "a\nb\nc"],
            'spaces around are trimmed, full-width and no-break too' => ["　 \n説明 文\t\u{00A0}\n", '説明 文'],
            'br in any form' => ['a<br>b<br/>c<br />d<BR>e<br class="x">f', "a\nb\nc\nd\ne\nf"],
            'end of a paragraph, div and list item' => ['<p>a</p><P>b</P ><div>c</div><ul><li>d</li><li>e</li></ul>', "a\nb\nc\nd\ne"],
            'other tags are removed and their text kept' => ['<b>太字</b>と<a href="https://example.com/">リンク</a>', '太字とリンク'],
            'a URL stays as text' => ['詳細 https://example.com/a?b=1&amp;c=2', '詳細 https://example.com/a?b=1&c=2'],
            'script and style are removed with their content' => ['a<script type="text/javascript">alert(1)</script>b<STYLE>p{}</STYLE>c', 'abc'],
            'escaped script is decoded and then removed' => ['a&lt;script&gt;alert(1)&lt;/script&gt;b', 'ab'],
            'escaped tag is decoded and then removed' => ['&lt;b&gt;太字&lt;/b&gt;', '太字'],
            'character references are decoded once' => ['&amp;amp; &quot;引用&quot; &#39;a&#39; &hellip; &#x3042;', '&amp; "引用" \'a\' … あ'],
            'text that looks like a tag is lost' => ['a<b', 'a'],
            'a lone less-than sign stays' => ['1 &lt; 2', '1 < 2'],
            '500 characters stay whole' => [str_repeat('あ', 500), str_repeat('あ', 500)],
            '501 characters are cut to 500 and an ellipsis' => [str_repeat('あ', 501), str_repeat('あ', 500) . '…'],
            'the limit counts characters after conversion' => ['<p>' . str_repeat('a', 500) . '</p>', str_repeat('a', 500)],
        ];
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
        (new Parser())->forMonth('<html>Login required</html>', new Month('2026-11'));
    }

    public function testRejectsTruncatedCalendar(): void
    {
        $this->expectException(RuntimeException::class);
        (new Parser())->forMonth("BEGIN:VCALENDAR\nVERSION:2.0", new Month('2026-11'));
    }

    public function testRejectsHighFrequencyRecurrence(): void
    {
        $this->expectException(RuntimeException::class);
        $this->parse("BEGIN:VEVENT\nUID:rapid\nDTSTART:20261101T000000Z\nRRULE:FREQ=SECONDLY\nEND:VEVENT");
    }
}
