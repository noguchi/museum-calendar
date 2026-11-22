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
            'iCal形式の非公開URL' => ['https://calendar.google.com/calendar/ical/museum%40group.calendar.google.com/private-' . self::TOKEN . '/basic.ics', 'museum@group.calendar.google.com', self::TOKEN],
            '@ をそのまま含むiCal形式の非公開URL' => ['https://calendar.google.com/calendar/ical/museum@gmail.com/private-' . self::TOKEN . '/basic.ics', 'museum@gmail.com', self::TOKEN],
            '前後の空白' => ["  https://calendar.google.com/calendar/ical/museum%40gmail.com/private-" . self::TOKEN . "/basic.ics\n", 'museum@gmail.com', self::TOKEN],
            '大文字と小文字が混ざった秘密の部分' => ['https://calendar.google.com/calendar/ical/museum%40gmail.com/private-AbC123/basic.ics', 'museum@gmail.com', 'AbC123'],
            '最長の秘密の部分' => ['https://calendar.google.com/calendar/ical/museum%40gmail.com/private-' . str_repeat('a', 128) . '/basic.ics', 'museum@gmail.com', str_repeat('a', 128)],
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
            'iCal形式の公開URL' => ['https://calendar.google.com/calendar/ical/ja.japanese%23holiday%40group.v.calendar.google.com/public/basic.ics', 'ja.japanese#holiday@group.v.calendar.google.com'],
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
            '記号を含む秘密の部分' => [$private('0123_4567')],
            'エンコードしたスラッシュを含む秘密の部分' => [$private('0123%2F4567')],
            '空の秘密の部分' => [$private('')],
            '長すぎる秘密の部分' => [$private(str_repeat('a', 129))],
            'パスが余分な非公開URL' => ['https://calendar.google.com/calendar/ical/museum%40gmail.com/private-' . self::TOKEN . '/extra/basic.ics'],
            '別の形式の非公開URL' => ['https://calendar.google.com/calendar/ical/museum%40gmail.com/private-' . self::TOKEN . '/full.ics'],
            'HTTPの非公開URL' => ['http://calendar.google.com/calendar/ical/museum%40gmail.com/private-' . self::TOKEN . '/basic.ics'],
            '別のホストの非公開URL' => ['https://www.google.com/calendar/ical/museum%40gmail.com/private-' . self::TOKEN . '/basic.ics'],
            'カレンダーを指さない非公開URL' => ['https://calendar.google.com/calendar/ical/%2F..%2Fetc/private-' . self::TOKEN . '/basic.ics'],
            'HTTPの公開URL' => ['http://calendar.google.com/calendar/ical/museum%40gmail.com/public/basic.ics'],
            '別のホストの公開URL' => ['https://calendar.example.com/calendar/ical/museum%40gmail.com/public/basic.ics'],
            'ポート付き' => ['https://calendar.google.com:8443/calendar/ical/museum%40gmail.com/public/basic.ics'],
            'ユーザー情報付き' => ['https://user:pass@calendar.google.com/calendar/ical/museum%40gmail.com/public/basic.ics'],
            '末尾に改行のあるカレンダーID' => ['https://calendar.google.com/calendar/ical/museum%40gmail.com%0A/public/basic.ics'],
            'カレンダーのページの公開URL' => ['https://calendar.google.com/calendar/embed?src=museum%40gmail.com'],
            '別のページ' => ['https://calendar.google.com/calendar/r?cid=museum%40gmail.com'],
            'URLでない' => ['museum@gmail.com'],
            '空' => [''],
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
        self::fail('URLを受け付けない。');
    }

    public function testRejectsASecretThatIsNotLettersAndDigits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GoogleCalendar('museum@gmail.com', 'secret/../x');
    }
}
