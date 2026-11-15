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
    $check(shortcode_exists('museum_calendar') && shortcode_exists('museum_calendar_today'), 'Shortcode registration failed.');
    $check(wp_style_is('museum-calendar', 'registered'), 'Themes need the registered stylesheet handle.');
    update_option('museum_calendar_demo', false);
    $check(str_contains(do_shortcode('[museum_calendar]'), '接続が設定されていません'), 'Disabled mode must not show sample data.');
    $check(str_contains(do_shortcode('[museum_calendar_today]'), '接続が設定されていません'), 'Disabled mode must not show a sample today.');
    update_option('museum_calendar_demo', true);
    $html = do_shortcode('[museum_calendar month="2024-02"]');
    $check(str_contains($html, '2024年2月'), 'Requested month was not rendered.');
    $check(str_contains($html, '2024-02-29'), 'Leap day is missing.');
    $check(!str_contains($html, '2024-02-30'), 'Impossible day was rendered.');
    foreach (['休館（サンプル）' => 4, '開館（サンプル）' => 25, 'ツアー（サンプル）' => 17, '茶室（サンプル）' => 8] as $title => $count) {
        $check(substr_count($html, "<strong>{$title}</strong>") === $count, "February 2024 must list {$title} {$count} times.");
    }
    $check(!str_contains($html, 'museum-calendar__nav'), 'Fixed month must not show ineffective navigation.');
    $check(!str_contains($html, 'aria-current'), 'A past month must not mark a current day.');
    $tokyo = new DateTimeZone('Asia/Tokyo');
    $now = new DateTimeImmutable('now', $tokyo);
    $today = $now->format('Y-m-d');
    $html = do_shortcode('[museum_calendar]');
    $check(preg_match('/<td aria-current="date">\s*<time datetime="' . $today . '">/', $html) === 1, 'The current month must mark today.');
    $html = do_shortcode('[museum_calendar_today]');
    $check(str_contains($html, '<time datetime="' . $today . '">' . $now->format('n/j') . '</time>'), 'Today shortcode must show the date in Asia/Tokyo.');
    $check(str_contains($html, '動作確認用サンプル'), 'Sample today must be labelled as a sample.');
    $samples = match ((int) $now->format('N')) {
        1 => ['休館（サンプル）'],
        6, 7 => ['開館（サンプル）', '茶室（サンプル）'],
        default => ['開館（サンプル）', 'ツアー（サンプル）'],
    };
    foreach (['休館（サンプル）', '開館（サンプル）', 'ツアー（サンプル）', '茶室（サンプル）'] as $title) {
        $check(str_contains($html, "<strong>{$title}</strong>") === in_array($title, $samples, true), "Today lists the wrong samples for its weekday: {$title}.");
    }
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

    $dateLine = static fn (string $date, string $label, string $weekday): string
        => '<time datetime="' . $date . '">' . $label . '</time><span class="museum-calendar-today__weekday">' . $weekday . '</span>';
    $html = (new Renderer($source))->renderToday(new DateTimeImmutable('2026-11-15 12:00', $tokyo));
    $check(str_contains($html, $dateLine('2026-11-15', '11/15', '日')), 'Today date or weekday is wrong on a Sunday.');
    $order = array_map(static fn (string $item) => strpos($html, $item), ['<strong>Zebra</strong>', '<strong>&lt;script&gt;alert(1)&lt;/script&gt;</strong>', '<strong>Alpha</strong>']);
    $check(!in_array(false, $order, true) && $order[0] < $order[1] && $order[1] < $order[2], 'Today must list its events in source order.');
    $check(!str_contains($html, '<script>') && !str_contains($html, '<img'), 'Today event was not escaped.');
    $check(str_contains($html, '<li data-title="&lt;script&gt;alert(1)&lt;/script&gt;">'), 'Today event title attribute is missing or unescaped.');
    $check(!str_contains($html, 'Tomorrow'), 'Today must not list another day.');
    $check(!str_contains($html, '本日の予定はありません') && !str_contains($html, '動作確認用サンプル') && !str_contains($html, 'role="status"'), 'Today showed a message that does not apply.');
    $html = (new Renderer($source))->renderToday(new DateTimeImmutable('2026-11-14 12:00', $tokyo));
    $check(str_contains($html, $dateLine('2026-11-14', '11/14', '土')), 'Today date or weekday is wrong on a Saturday.');
    $check(str_contains($html, '本日の予定はありません。') && !str_contains($html, '<ul'), 'A day without events must say so.');
    $html = (new Renderer($source))->renderToday(new DateTimeImmutable('2026-11-16 12:00', $tokyo));
    $check(str_contains($html, $dateLine('2026-11-16', '11/16', '月')) && str_contains($html, '<strong>Tomorrow</strong>'), 'Today is wrong on a Monday.');
    $html = (new Renderer($source))->renderToday(new DateTimeImmutable('2027-01-05 12:00', $tokyo));
    $check(str_contains($html, $dateLine('2027-01-05', '1/5', '火')), 'Today date must not be zero-padded.');
    $html = (new Renderer($source))->renderToday(new DateTimeImmutable('2026-11-14 15:30', new DateTimeZone('UTC')));
    $check(str_contains($html, $dateLine('2026-11-15', '11/15', '日')) && str_contains($html, '<strong>Zebra</strong>'), 'Today must be judged in Asia/Tokyo.');
    $html = (new Renderer($source, false, '<b>前回の予定</b>'))->renderToday(new DateTimeImmutable('2026-11-15 12:00', $tokyo));
    $check(str_contains($html, '<p class="museum-calendar-today__notice" role="status">&lt;b&gt;前回の予定&lt;/b&gt;</p>'), 'Stale notice is missing or unescaped in today.');
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
