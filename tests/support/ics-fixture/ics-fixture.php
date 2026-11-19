<?php
/** Plugin Name: Museum Calendar Test Fixtures (tests only) */
if (!defined('ABSPATH') || !defined('MUSEUM_CALENDAR_TEST_FIXTURES') || !MUSEUM_CALENDAR_TEST_FIXTURES
    || wp_get_environment_type() !== 'development') {
    return;
}
add_filter('pre_http_request', static function ($pre, $args, $url) {
    if ($url !== 'https://calendar.google.com/calendar/ical/museum-fixture%40group.calendar.google.com/public/basic.ics') {
        return $pre;
    }
    return [
        'response' => ['code' => 200],
        'headers' => ['content-type' => 'text/calendar'],
        'body' => file_get_contents(ABSPATH . 'workspace/tests/fixtures/museum.ics'),
    ];
}, 10, 3);

// The browser tests cannot choose the date, so a query argument chooses what today holds.
add_filter('museum_calendar_events', static function (array $events, string $month): array {
    $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
    $fixture = $_GET['museum_fixture_today'] ?? '';
    if (!is_string($fixture) || $today->format('Y-m') !== $month) {
        return $events;
    }
    $open = ['title' => 'OPEN', 'time' => '10:30–16:30', 'all_day' => false, 'start' => '10:30', 'end' => '16:30'];
    $close = ['title' => 'CLOSE', 'time' => '終日', 'all_day' => true, 'start' => '', 'end' => ''];
    $tour = ['title' => 'ガイドツアー開催日', 'time' => '11:00–12:00', 'all_day' => false, 'start' => '11:00', 'end' => '12:00'];
    $day = match ($fixture) {
        'open' => [$open, $tour],
        'closed' => [$open, $close],
        'none' => [],
        default => null,
    };
    if ($day !== null) {
        $events[$today->format('Y-m-d')] = $day;
    }
    return $events;
}, 10, 2);

// The test site installs no language pack. These two filters let switch_to_locale('ja') load the names in ja.l10n.php.
add_filter('get_available_languages', static fn (array $languages): array => array_values(array_unique([...$languages, 'ja'])));
add_filter('load_translation_file', static function (string $file, string $domain, string $locale): string {
    return $domain === 'default' && $locale === 'ja' && basename($file) === 'ja.l10n.php' ? __DIR__ . '/ja.l10n.php' : $file;
}, 10, 3);
