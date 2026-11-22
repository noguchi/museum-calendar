<?php
declare(strict_types=1);

use MuseumCalendar\Ics\GoogleCalendar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GoogleCalendarTest extends TestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef';

    public static function privateUrls(): array
    {
        return [
            'private iCal address' => ['https://calendar.google.com/calendar/ical/museum%40group.calendar.google.com/private-' . self::TOKEN . '/basic.ics', 'museum@group.calendar.google.com', self::TOKEN],
            'private iCal address with a raw @' => ['https://calendar.google.com/calendar/ical/museum@gmail.com/private-' . self::TOKEN . '/basic.ics', 'museum@gmail.com', self::TOKEN],
            'surrounding spaces' => ["  https://calendar.google.com/calendar/ical/museum%40gmail.com/private-" . self::TOKEN . "/basic.ics\n", 'museum@gmail.com', self::TOKEN],
            'mixed-case token' => ['https://calendar.google.com/calendar/ical/museum%40gmail.com/private-AbC123/basic.ics', 'museum@gmail.com', 'AbC123'],
            'longest token' => ['https://calendar.google.com/calendar/ical/museum%40gmail.com/private-' . str_repeat('a', 128) . '/basic.ics', 'museum@gmail.com', str_repeat('a', 128)],
        ];
    }

    #[DataProvider('privateUrls')]
    public function testReadsTheCalendarIdAndTheSecretFromAPrivateUrl(string $url, string $id, string $secret): void
    {
        $calendar = GoogleCalendar::fromUrl($url);
        self::assertSame($id, $calendar->id);
        self::assertSame($secret, $calendar->secret);
        self::assertTrue($calendar->isPrivate());
    }

    public static function publicUrls(): array
    {
        return [
            'public iCal address' => ['https://calendar.google.com/calendar/ical/ja.japanese%23holiday%40group.v.calendar.google.com/public/basic.ics', 'ja.japanese#holiday@group.v.calendar.google.com'],
        ];
    }

    #[DataProvider('publicUrls')]
    public function testReadsTheCalendarIdFromAPublicUrl(string $url, string $id): void
    {
        $calendar = GoogleCalendar::fromUrl($url);
        self::assertSame($id, $calendar->id);
        self::assertNull($calendar->secret);
        self::assertFalse($calendar->isPrivate());
    }

    public function testBuildsThePublicFeedUrl(): void
    {
        $calendar = new GoogleCalendar('ja.japanese#holiday@group.v.calendar.google.com');
        self::assertSame('https://calendar.google.com/calendar/ical/ja.japanese%23holiday%40group.v.calendar.google.com/public/basic.ics', $calendar->feedUrl());
        self::assertEquals($calendar, GoogleCalendar::fromUrl($calendar->feedUrl()));
    }

    public function testBuildsThePrivateFeedUrlWithTheSecret(): void
    {
        $calendar = new GoogleCalendar('museum@group.calendar.google.com', self::TOKEN);
        self::assertSame('https://calendar.google.com/calendar/ical/museum%40group.calendar.google.com/private-' . self::TOKEN . '/basic.ics', $calendar->feedUrl());
        self::assertEquals($calendar, GoogleCalendar::fromUrl($calendar->feedUrl()));
    }

    public function testKeepsTheSecretOutOfDebugOutput(): void
    {
        $calendar = new GoogleCalendar('museum@group.calendar.google.com', self::TOKEN);
        self::assertStringNotContainsString(self::TOKEN, print_r($calendar, true));
        ob_start();
        var_dump($calendar);
        self::assertStringNotContainsString(self::TOKEN, (string) ob_get_clean());
    }

    public static function rejectedUrls(): array
    {
        $private = static fn (string $token): string => 'https://calendar.google.com/calendar/ical/museum%40gmail.com/private-' . $token . '/basic.ics';
        return [
            'token with a symbol' => [$private('0123_4567')],
            'token with an encoded slash' => [$private('0123%2F4567')],
            'empty token' => [$private('')],
            'token too long' => [$private(str_repeat('a', 129))],
            'private address with an extra path' => ['https://calendar.google.com/calendar/ical/museum%40gmail.com/private-' . self::TOKEN . '/extra/basic.ics'],
            'private address of another format' => ['https://calendar.google.com/calendar/ical/museum%40gmail.com/private-' . self::TOKEN . '/full.ics'],
            'private address over HTTP' => ['http://calendar.google.com/calendar/ical/museum%40gmail.com/private-' . self::TOKEN . '/basic.ics'],
            'private address on another host' => ['https://www.google.com/calendar/ical/museum%40gmail.com/private-' . self::TOKEN . '/basic.ics'],
            'private address of no calendar' => ['https://calendar.google.com/calendar/ical/%2F..%2Fetc/private-' . self::TOKEN . '/basic.ics'],
            'public address over HTTP' => ['http://calendar.google.com/calendar/ical/museum%40gmail.com/public/basic.ics'],
            'public address on another host' => ['https://calendar.example.com/calendar/ical/museum%40gmail.com/public/basic.ics'],
            'a port' => ['https://calendar.google.com:8443/calendar/ical/museum%40gmail.com/public/basic.ics'],
            'credentials' => ['https://user:pass@calendar.google.com/calendar/ical/museum%40gmail.com/public/basic.ics'],
            'a calendar ID with a trailing newline' => ['https://calendar.google.com/calendar/ical/museum%40gmail.com%0A/public/basic.ics'],
            'the public URL of the calendar page' => ['https://calendar.google.com/calendar/embed?src=museum%40gmail.com'],
            'another page' => ['https://calendar.google.com/calendar/r?cid=museum%40gmail.com'],
            'not a URL' => ['museum@gmail.com'],
            'empty' => [''],
        ];
    }

    #[DataProvider('rejectedUrls')]
    public function testRejectsAnythingButAGoogleCalendarUrlOfOneCalendar(string $url): void
    {
        try {
            GoogleCalendar::fromUrl($url);
        } catch (InvalidArgumentException $error) {
            self::assertStringNotContainsString('museum', $error->getMessage());
            self::assertStringNotContainsString('0123', $error->getMessage());
            return;
        }
        self::fail('The URL was accepted.');
    }

    public function testRejectsASecretThatIsNotLettersAndDigits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GoogleCalendar('museum@gmail.com', 'secret/../x');
    }
}
