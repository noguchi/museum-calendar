<?php
declare(strict_types=1);

use MuseumCalendar\Ics\GoogleCalendar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GoogleCalendarTest extends TestCase
{
    public static function publicUrls(): array
    {
        return [
            'public URL' => ['https://calendar.google.com/calendar/embed?src=museum%40group.calendar.google.com&ctz=Asia%2FTokyo', 'museum@group.calendar.google.com'],
            'public URL with a raw @' => ['https://calendar.google.com/calendar/embed?ctz=Asia%2FTokyo&src=museum@gmail.com', 'museum@gmail.com'],
            'surrounding spaces' => ["  https://calendar.google.com/calendar/embed?src=museum%40gmail.com\n", 'museum@gmail.com'],
            'public URL of a signed-in browser' => ['https://calendar.google.com/calendar/u/0/embed?src=museum%40gmail.com', 'museum@gmail.com'],
            'public iCal address' => ['https://calendar.google.com/calendar/ical/ja.japanese%23holiday%40group.v.calendar.google.com/public/basic.ics', 'ja.japanese#holiday@group.v.calendar.google.com'],
        ];
    }

    #[DataProvider('publicUrls')]
    public function testReadsTheCalendarIdFromAPublicUrl(string $url, string $id): void
    {
        self::assertSame($id, GoogleCalendar::fromUrl($url)->id);
    }

    public function testBuildsThePublicUrlAndTheFeedUrl(): void
    {
        $calendar = new GoogleCalendar('ja.japanese#holiday@group.v.calendar.google.com');
        self::assertSame('https://calendar.google.com/calendar/embed?src=ja.japanese%23holiday%40group.v.calendar.google.com', $calendar->publicUrl());
        self::assertSame('https://calendar.google.com/calendar/ical/ja.japanese%23holiday%40group.v.calendar.google.com/public/basic.ics', $calendar->feedUrl());
        self::assertSame($calendar->id, GoogleCalendar::fromUrl($calendar->publicUrl())->id);
        self::assertSame($calendar->id, GoogleCalendar::fromUrl($calendar->feedUrl())->id);
    }

    public static function rejectedUrls(): array
    {
        return [
            'secret iCal address' => ['https://calendar.google.com/calendar/ical/museum%40gmail.com/private-0123456789abcdef/basic.ics'],
            'HTTP' => ['http://calendar.google.com/calendar/embed?src=museum%40gmail.com'],
            'another host' => ['https://calendar.example.com/calendar/embed?src=museum%40gmail.com'],
            'a port' => ['https://calendar.google.com:8443/calendar/embed?src=museum%40gmail.com'],
            'credentials' => ['https://user:pass@calendar.google.com/calendar/embed?src=museum%40gmail.com'],
            'no calendar' => ['https://calendar.google.com/calendar/embed?ctz=Asia%2FTokyo'],
            'two calendars' => ['https://calendar.google.com/calendar/embed?src=a%40gmail.com&src=b%40gmail.com'],
            'not a calendar ID' => ['https://calendar.google.com/calendar/embed?src=%2F..%2Fetc'],
            'a trailing newline' => ['https://calendar.google.com/calendar/embed?src=museum%40gmail.com%0A'],
            'another page' => ['https://calendar.google.com/calendar/r?cid=museum%40gmail.com'],
            'not a URL' => ['museum@gmail.com'],
            'empty' => [''],
        ];
    }

    #[DataProvider('rejectedUrls')]
    public function testRejectsAnythingButAPublicUrlOfOneCalendar(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        GoogleCalendar::fromUrl($url);
    }
}
