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
    $check(str_contains($html, 'サンプル展示'), 'Event source was not rendered.');
    $check(!str_contains($html, 'museum-calendar__nav'), 'Fixed month must not show ineffective navigation.');
    $check(str_contains(do_shortcode('[museum_calendar month="invalid"]'), '正しくありません'), 'Invalid input was accepted.');

    $source = new class implements EventSource {
        public function forMonth(Month $month): array
        {
            return [$month->key() . '-01' => [['title' => '<script>alert(1)</script>', 'time' => '<img src=x onerror=alert(1)>']]];
        }
    };
    $html = (new Renderer($source))->render(new Month('2026-11'));
    $check(!str_contains($html, '<script>'), 'Event title was not escaped.');
    $check(!str_contains($html, '<img'), 'Event time was not escaped.');
    $check(str_contains($html, '&lt;script&gt;'), 'Escaped text was lost.');
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
