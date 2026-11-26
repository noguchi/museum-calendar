<?php
/** Plugin Name: Museum Calendar Test Fixtures (tests only) */
if (!defined('ABSPATH') || !defined('MUSEUM_CALENDAR_TEST_FIXTURES') || !MUSEUM_CALENDAR_TEST_FIXTURES
    || wp_get_environment_type() !== 'development') {
    return;
}
// ブラウザーテストは、テスト用のカレンダーを公開URLと、作った秘密の部分のiCal形式の非公開URLで登録する。
add_filter('pre_http_request', static function ($pre, $args, $url) {
    if (!in_array($url, [
        'https://calendar.google.com/calendar/ical/museum-fixture%40group.calendar.google.com/public/basic.ics',
        'https://calendar.google.com/calendar/ical/museum-fixture%40group.calendar.google.com/private-0123456789abcdef0123456789abcdef/basic.ics',
    ], true)) {
        return $pre;
    }
    return [
        'response' => ['code' => 200],
        'headers' => ['content-type' => 'text/calendar'],
        'body' => file_get_contents(ABSPATH . 'workspace/tests/fixtures/museum.ics'),
    ];
}, 10, 3);

// ブラウザーテストは日付を選べないので、クエリの引数で本日の予定を選ぶ。
// 保存した取得済みのデータを、このリクエストの間だけ置き換える。今取得したことにするので、再取得されず、stale にもならない。
add_filter('option_museum_calendar_feed', static function ($state) {
    $fixture = $_GET['museum_fixture_today'] ?? '';
    $day = new DateTimeImmutable('today', new DateTimeZone('Asia/Tokyo'));
    $today = $day->format('Ymd');
    $event = static fn (string $uid, string $title, string $start, string $end): string
        => "BEGIN:VEVENT\r\nUID:{$uid}@museum-fixture-today\r\n{$start}\r\n{$end}\r\nSUMMARY:{$title}\r\nEND:VEVENT\r\n";
    $open = $event('open', '開館日', "DTSTART:{$today}T103000", "DTEND:{$today}T163000");
    $tour = $event('tour', 'ガイドツアー開催日', "DTSTART:{$today}T110000", "DTEND:{$today}T120000");
    $events = match ($fixture) {
        'open' => $open . $tour,
        'closed' => $open . $event('closed', '休館日', "DTSTART;VALUE=DATE:{$today}", 'DTEND;VALUE=DATE:' . $day->modify('+1 day')->format('Ymd')),
        'none' => $tour,
        default => null,
    };
    if ($events === null || !is_array($state)) {
        return $state;
    }
    $body = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//museum-calendar//fixture//JA\r\nX-WR-TIMEZONE:Asia/Tokyo\r\n{$events}END:VCALENDAR\r\n";
    return ['body' => $body, 'fetched_at' => time(), 'attempted_at' => time(), 'error' => ''] + $state;
});

// テスト環境には言語パックを導入しない。この2つのフィルターで、switch_to_locale('ja') が ja.l10n.php の名前を読めるようにする。
add_filter('get_available_languages', static fn (array $languages): array => array_values(array_unique([...$languages, 'ja'])));
add_filter('load_translation_file', static function (string $file, string $domain, string $locale): string {
    return $domain === 'default' && $locale === 'ja' && basename($file) === 'ja.l10n.php' ? __DIR__ . '/ja.l10n.php' : $file;
}, 10, 3);
