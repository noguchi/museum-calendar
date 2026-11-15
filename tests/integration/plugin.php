<?php
/** Integration checks against a real WordPress installation. */
use MuseumCalendar\Calendar\EventSource;
use MuseumCalendar\Calendar\Month;
use MuseumCalendar\Frontend\Renderer;

if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'development') {
    exit(1);
}
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$previous = get_option('museum_calendar_demo', null);
$previousUrl = get_option('museum_calendar_ics_url', null);
delete_option('museum_calendar_ics_url');
try {
    $check(shortcode_exists('museum_calendar'), 'Shortcode registration failed.');
    update_option('museum_calendar_demo', false);
    $check(str_contains(do_shortcode('[museum_calendar]'), '接続が設定されていません'), 'Disabled mode must not show sample data.');
    update_option('museum_calendar_demo', true);
    $html = do_shortcode('[museum_calendar month="2024-02"]');
    $check(str_contains($html, '2024年2月'), 'Requested month was not rendered.');
    $check(str_contains($html, '2024-02-29'), 'Leap day is missing.');
    $check(!str_contains($html, '2024-02-30'), 'Impossible day was rendered.');
    foreach (['サンプル休館' => 4, 'サンプル開館' => 25, 'サンプルツアー' => 17, 'サンプル茶室' => 8] as $title => $count) {
        $check(substr_count($html, "<strong>{$title}</strong>") === $count, "February 2024 must list {$title} {$count} times.");
    }
    $check(!str_contains($html, 'museum-calendar__nav'), 'Fixed month must not show ineffective navigation.');
    $check(!str_contains($html, 'aria-current'), 'A past month must not mark a current day.');
    $tokyo = new DateTimeZone('Asia/Tokyo');
    $today = (new DateTimeImmutable('now', $tokyo))->format('Y-m-d');
    $html = do_shortcode('[museum_calendar]');
    $check(preg_match('/<td aria-current="date">\s*<time datetime="' . $today . '">/', $html) === 1, 'The current month must mark today.');
    $check(str_contains(do_shortcode('[museum_calendar month="invalid"]'), '正しくありません'), 'Invalid input was accepted.');

    $source = new class implements EventSource {
        public function forMonth(Month $month): array
        {
            return [
                $month->key() . '-15' => [
                    ['title' => 'Zebra', 'time' => '終日'],
                    ['title' => '<script>alert(1)</script>', 'time' => '<img src=x onerror=alert(1)>'],
                    ['title' => 'Alpha', 'time' => '13:00'],
                ],
                $month->key() . '-16' => [['title' => 'Tomorrow', 'time' => '終日']],
            ];
        }
    };
    $currentCell = '/<td aria-current="date">\s*<time datetime="2026-11-15">/';
    $html = (new Renderer($source))->render(new Month('2026-11'), new DateTimeImmutable('2026-11-15 12:00', $tokyo));
    $check(!str_contains($html, '<script>'), 'Event title was not escaped.');
    $check(!str_contains($html, '<img'), 'Event time was not escaped.');
    $check(str_contains($html, '&lt;script&gt;'), 'Escaped text was lost.');
    $check(str_contains($html, '<li data-title="&lt;script&gt;alert(1)&lt;/script&gt;">'), 'Event title attribute is missing or unescaped.');
    $check(str_contains($html, '<section class="museum-calendar" id="museum-calendar"'), 'Calendar anchor is missing.');
    $check(substr_count($html, 'aria-current="date"') === 1 && preg_match($currentCell, $html) === 1, 'Today must be the only current cell.');
    preg_match_all('/<a href="([^"]*)"/', $html, $links);
    $check(count($links[1]) === 2, 'Month navigation is missing.');
    $check(str_contains($links[1][0], 'museum_month=2026-10') && str_ends_with($links[1][0], '#museum-calendar'), 'Previous month link must return to the calendar.');
    $check(str_contains($links[1][1], 'museum_month=2026-12') && str_ends_with($links[1][1], '#museum-calendar'), 'Next month link must return to the calendar.');
    $html = (new Renderer($source))->render(new Month('2026-11'), new DateTimeImmutable('2026-12-01 00:00', $tokyo));
    $check(!str_contains($html, 'aria-current'), 'A month without today must have no current cell.');
    $html = (new Renderer($source))->render(new Month('2026-11'), new DateTimeImmutable('2026-11-14 15:30', new DateTimeZone('UTC')));
    $check(preg_match($currentCell, $html) === 1, 'Today must be judged in Asia/Tokyo.');
} finally {
    if ($previousUrl === null) {
        delete_option('museum_calendar_ics_url');
    } else {
        update_option('museum_calendar_ics_url', $previousUrl, false);
    }
    if ($previous === null) {
        delete_option('museum_calendar_demo');
    } else {
        update_option('museum_calendar_demo', $previous);
    }
}
WP_CLI::success("Integration: {$checks} checks passed.");
