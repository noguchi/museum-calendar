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
    $check(str_contains($html, '<caption>February 2024</caption>'), 'Requested month was not rendered.');
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
    $check(preg_match('/<td data-weekday="' . $now->format('N') . '" aria-current="date">\s*<time datetime="' . $today . '">/', $html) === 1, 'The current month must mark today.');
    $dateLine = static fn (string $date, string $label): string
        => '<p class="museum-calendar-today__date"><time datetime="' . $date . '">' . $label . '</time></p>';
    $weekdayNames = ['日', '月', '火', '水', '木', '金', '土'];
    $inJapanese = require dirname(__DIR__) . '/support/japanese-locale.php';
    $dateFormat = get_option('date_format');
    try {
        update_option('date_format', 'Y.m.d');
        $html = do_shortcode('[museum_calendar_today]');
        $check(str_contains($html, $dateLine($today, $now->format('Y.m.d'))), 'Today shortcode must show the date in Asia/Tokyo, in the WordPress date format.');
        $check(!str_contains($html, 'museum-calendar-today__weekday'), 'Today must not print a weekday outside the date format.');
        update_option('date_format', 'n月j日(D)');
        $check(str_contains($inJapanese(static fn (): string => do_shortcode('[museum_calendar_today]')), $dateLine($today, $now->format('n月j日') . '(' . $weekdayNames[(int) $now->format('w')] . ')')), 'A date format with a weekday must print the weekday of the site language.');
        $check(str_contains(do_shortcode('[museum_calendar_today]'), $dateLine($today, wp_date('n月j日(D)', $now->getTimestamp(), $tokyo))), 'A date format with a weekday is wrong in the language of the test site.');
        $fallback = $dateLine($today, $now->format('Y年n月j日'));
        update_option('date_format', '');
        $check(get_option('date_format') === '', 'The empty date format was not stored.');
        $check(str_contains(do_shortcode('[museum_calendar_today]'), $fallback), 'An empty date format must fall back to Y年n月j日.');
        foreach ([false, null, 0, []] as $invalid) {
            $stored = static fn () => $invalid;
            add_filter('option_date_format', $stored);
            try {
                $check(str_contains(do_shortcode('[museum_calendar_today]'), $fallback), 'A date format that is not a string must fall back to Y年n月j日: ' . var_export($invalid, true));
            } finally {
                remove_filter('option_date_format', $stored);
            }
        }
    } finally {
        update_option('date_format', $dateFormat);
    }
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

    $grid = static function (string $html): array {
        preg_match_all('/<th scope="col" data-weekday="(\d)">([^<]*)<\/th>/', $html, $head);
        $rows = [];
        foreach (array_slice(explode('<tr>', explode('<tbody>', $html)[1]), 1) as $row) {
            preg_match_all('/<td(?: data-weekday="(\d)")?[^>]*>\s*(?:<time datetime="([^"]*)">)?/', $row, $cells, PREG_SET_ORDER);
            $rows[] = array_map(static fn (array $cell): string => isset($cell[2]) ? $cell[1] . ' ' . $cell[2] : '-', $cells);
        }
        return ['numbers' => implode('', $head[1]), 'labels' => implode(' ', $head[2]), 'rows' => $rows];
    };
    $todayHtml = do_shortcode('[museum_calendar_today]');
    $startOfWeek = get_option('start_of_week');
    try {
        update_option('start_of_week', 0);
        $sunday = $grid(do_shortcode('[museum_calendar month="2026-11"]'));
        $check($sunday['labels'] === 'Sun Mon Tue Wed Thu Fri Sat' && $sunday['numbers'] === '7123456', 'Week start 0 must put Sunday in the first column.');
        $check($sunday['rows'][0] === ['7 2026-11-01', '1 2026-11-02', '2 2026-11-03', '3 2026-11-04', '4 2026-11-05', '5 2026-11-06', '6 2026-11-07'], 'A month that starts on a Sunday must fill the first Sunday-first week.');
        $check(count($sunday['rows']) === 5 && $sunday['rows'][4] === ['7 2026-11-29', '1 2026-11-30', '-', '-', '-', '-', '-'], 'The last Sunday-first week of November 2026 is wrong.');
        $html = do_shortcode('[museum_calendar month="2026-12"]');
        $sunday = $grid($html);
        $check($sunday['rows'][0] === ['-', '-', '2 2026-12-01', '3 2026-12-02', '4 2026-12-03', '5 2026-12-04', '6 2026-12-05'], 'Tuesday 1 December 2026 must sit in the third Sunday-first column.');
        $check($sunday['rows'][1][0] === '7 2026-12-06' && $sunday['rows'][4] === ['7 2026-12-27', '1 2026-12-28', '2 2026-12-29', '3 2026-12-30', '4 2026-12-31', '-', '-'], 'Sunday-first dates must sit under their weekday.');
        $check(str_contains($html, '<caption>December 2026</caption>') && !str_contains($html, 'museum-calendar__nav'), 'A fixed month must still render without navigation when the week starts on Sunday.');
        $check(preg_match('/<td data-weekday="1"[^>]*>\s*<time datetime="2026-12-07">7<\/time>\s*<ul>\s*<li data-title="休館（サンプル）">/', $html) === 1, 'The Monday sample must stay on Mondays when the week starts on Sunday.');
        $html = do_shortcode('[museum_calendar]');
        $check($grid($html)['labels'] === 'Sun Mon Tue Wed Thu Fri Sat', 'The current month must follow the week start.');
        $check(preg_match('/<td data-weekday="' . $now->format('N') . '" aria-current="date">\s*<time datetime="' . $today . '">/', $html) === 1, 'Today must keep its weekday when the week starts on Sunday.');
        $check(preg_match_all('/<a href="[^"]*museum_month=\d{4}-\d{2}#museum-calendar"/', $html) === 2, 'Month links are missing when the week starts on Sunday.');
        $check(do_shortcode('[museum_calendar_today]') === $todayHtml, 'The week start must not change the today shortcode.');

        update_option('start_of_week', 3);
        $wednesday = $grid(do_shortcode('[museum_calendar month="2026-12"]'));
        $check($wednesday['labels'] === 'Wed Thu Fri Sat Sun Mon Tue' && $wednesday['numbers'] === '3456712', 'Week start 3 must put Wednesday in the first column.');
        $japanese = $grid($inJapanese(static fn (): string => do_shortcode('[museum_calendar month="2026-12"]')));
        $check($japanese['labels'] === '水 木 金 土 日 月 火' && $japanese['numbers'] === '3456712', 'Weekday headings must use the site language and keep their ISO 8601 numbers.');
        $check($japanese['rows'] === $wednesday['rows'], 'The site language must not move the dates.');
        $check($wednesday['rows'][0] === ['-', '-', '-', '-', '-', '-', '2 2026-12-01'] && $wednesday['rows'][1][0] === '3 2026-12-02', 'Wednesday-first dates must sit under their weekday.');

        update_option('start_of_week', 1);
        $monday = $grid(do_shortcode('[museum_calendar month="2026-11"]'));
        $check($monday['labels'] === 'Mon Tue Wed Thu Fri Sat Sun' && $monday['numbers'] === '1234567', 'Week start 1 must put Monday in the first column.');
        $check($monday['rows'][0] === ['-', '-', '-', '-', '-', '-', '7 2026-11-01'] && count($monday['rows']) === 6, 'Monday-first November 2026 must start in the last column.');

        update_option('start_of_week', 9);
        $check((int) get_option('start_of_week') === 9, 'The out-of-range week start was not stored.');
        $check($grid(do_shortcode('[museum_calendar month="2026-11"]')) === $monday, 'An out-of-range week start must render Monday first.');
        update_option('start_of_week', 0);
        foreach (['abc', '1.5', '-1', false, []] as $invalid) {
            $stored = static fn () => $invalid;
            add_filter('option_start_of_week', $stored);
            try {
                $check($grid(do_shortcode('[museum_calendar month="2026-11"]')) === $monday, 'A week start that is not 0 to 6 must render Monday first: ' . var_export($invalid, true));
            } finally {
                remove_filter('option_start_of_week', $stored);
            }
        }
    } finally {
        update_option('start_of_week', $startOfWeek);
    }

    $source = new class implements EventSource {
        public function forMonth(Month $month): array
        {
            return [
                $month->key() . '-15' => [
                    ['title' => 'Zebra', 'time' => '終日', 'all_day' => true, 'start' => '', 'end' => ''],
                    ['title' => '<script>alert(1)</script>', 'time' => '<img src=x onerror=alert(1)>', 'all_day' => false, 'start' => '', 'end' => ''],
                    ['title' => 'Alpha', 'time' => '13:00', 'all_day' => false, 'start' => '13:00', 'end' => ''],
                ],
                $month->key() . '-16' => [['title' => 'Tomorrow', 'time' => '終日', 'all_day' => true, 'start' => '', 'end' => '']],
            ];
        }
    };
    $currentCell = '/<td data-weekday="7" aria-current="date">\s*<time datetime="2026-11-15">/';
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

    $header = static function (string $html): array {
        preg_match('/<nav class="museum-calendar__nav" aria-label="([^"]*)">(.*?)<\/nav>/s', $html, $nav);
        preg_match_all('/<a href="[^"]*[?;]museum_month=(\d{4}-\d{2})#museum-calendar">([^<]*)<\/a>/', $nav[2] ?? '', $links, PREG_SET_ORDER);
        preg_match('/<caption>([^<]*)<\/caption>/', $html, $caption);
        return [
            'nav' => $nav[1] ?? null,
            'links' => array_map(static fn (array $link): string => $link[1] . ' ' . $link[2], $links),
            'caption' => $caption[1] ?? null,
        ];
    };
    $monthHtml = static fn (string $month, bool $showNavigation = true): string
        => (new Renderer($source))->render(new Month($month), new DateTimeImmutable('2026-11-15 12:00', $tokyo), $showNavigation);
    $november = ['nav' => 'Previous and next months', 'links' => ['2026-10 &laquo; October', '2026-12 December &raquo;'], 'caption' => 'November 2026'];
    $check($header($monthHtml('2026-11')) === $november, 'The month header is wrong in the language of the test site.');
    $check($header($monthHtml('2026-11', false)) === ['nav' => null, 'links' => [], 'caption' => 'November 2026'], 'A month without navigation must keep its heading and print no links.');
    $check($header($inJapanese(static fn (): string => $monthHtml('2026-11'))) === ['nav' => 'Previous and next months', 'links' => ['2026-10 &laquo; 10月', '2026-12 12月 &raquo;'], 'caption' => '11月 2026'], 'Month names in the header must use the site language.');
    foreach ([
        '2026-01' => [['2025-12 &laquo; December', '2026-02 February &raquo;'], 'January 2026', ['2025-12 &laquo; 12月', '2026-02 2月 &raquo;']],
        '2026-12' => [['2026-11 &laquo; November', '2027-01 January &raquo;'], 'December 2026', ['2026-11 &laquo; 11月', '2027-01 1月 &raquo;']],
        '1900-01' => [['1900-02 February &raquo;'], 'January 1900', ['1900-02 2月 &raquo;']],
        '2100-12' => [['2100-11 &laquo; November'], 'December 2100', ['2100-11 &laquo; 11月']],
    ] as $month => [$links, $caption, $japaneseLinks]) {
        $shown = $header($monthHtml($month));
        $check($shown['links'] === $links && $shown['caption'] === $caption, "The month links or the heading are wrong for {$month}.");
        $check($header($inJapanese(static fn (): string => $monthHtml($month)))['links'] === $japaneseLinks, "The month links are wrong in Japanese for {$month}.");
    }
    add_filter('pre_option_timezone_string', $losAngeles = static fn (): string => 'America/Los_Angeles');
    try {
        $check(wp_timezone_string() === 'America/Los_Angeles', 'The site timezone was not replaced.');
        $check($header($monthHtml('2026-11')) === $november, 'The month header must name months in Asia/Tokyo, whatever the site timezone is.');
    } finally {
        remove_filter('pre_option_timezone_string', $losAngeles);
    }
    $monthly = 'Y年n月';
    $monthlyFormat = static function (string $translation, string $text, string $context, string $domain) use (&$monthly): string {
        return $text === 'F Y' && $context === 'monthly archives date format' && $domain === 'default' ? $monthly : $translation;
    };
    $navLabel = static fn (string $translation, string $text, string $domain): string
        => $text === 'Previous and next months' && $domain === 'default' ? '前と次の月' : $translation;
    add_filter('gettext_with_context', $monthlyFormat, 10, 4);
    add_filter('gettext', $navLabel, 10, 3);
    try {
        $check($header($inJapanese(static fn (): string => $monthHtml('2026-11'))) === ['nav' => '前と次の月', 'links' => ['2026-10 &laquo; 10月', '2026-12 12月 &raquo;'], 'caption' => '2026年11月'], 'The month header must use the WordPress translations of the monthly format and of the navigation label.');
        $monthly = '<b>Y</b>';
        $check(str_contains($monthHtml('2026-11'), '<caption>&lt;b&gt;2026&lt;/b&gt;</caption>'), 'The month heading was not escaped.');
    } finally {
        remove_filter('gettext_with_context', $monthlyFormat, 10);
        remove_filter('gettext', $navLabel, 10);
    }

    $html = (new Renderer($source))->renderToday(new DateTimeImmutable('2026-11-15 12:00', $tokyo), 'n/j');
    $check(str_contains($html, $dateLine('2026-11-15', '11/15')), 'Today date is wrong on a Sunday.');
    $order = array_map(static fn (string $item) => strpos($html, $item), ['<strong>Zebra</strong>', '<strong>&lt;script&gt;alert(1)&lt;/script&gt;</strong>', '<strong>Alpha</strong>']);
    $check(!in_array(false, $order, true) && $order[0] < $order[1] && $order[1] < $order[2], 'Today must list its events in source order.');
    $check(!str_contains($html, '<script>') && !str_contains($html, '<img'), 'Today event was not escaped.');
    $check(str_contains($html, '<li data-title="&lt;script&gt;alert(1)&lt;/script&gt;">'), 'Today event title attribute is missing or unescaped.');
    $check(!str_contains($html, 'Tomorrow'), 'Today must not list another day.');
    $check(!str_contains($html, '本日の予定はありません') && !str_contains($html, '動作確認用サンプル') && !str_contains($html, 'role="status"'), 'Today showed a message that does not apply.');
    $html = (new Renderer($source))->renderToday(new DateTimeImmutable('2026-11-14 12:00', $tokyo), 'Y年n月j日');
    $check(str_contains($html, $dateLine('2026-11-14', '2026年11月14日')), 'Today date is wrong on a Saturday.');
    $check(str_contains($html, '本日の予定はありません。') && !str_contains($html, '<ul'), 'A day without events must say so.');
    $html = $inJapanese(static fn (): string => (new Renderer($source))->renderToday(new DateTimeImmutable('2026-11-16 12:00', $tokyo), 'n月j日(D)'));
    $check(str_contains($html, $dateLine('2026-11-16', '11月16日(月)')) && str_contains($html, '<strong>Tomorrow</strong>'), 'Today is wrong on a Monday.');
    $html = (new Renderer($source))->renderToday(new DateTimeImmutable('2027-01-05 12:00', $tokyo), 'Y/m/d n/j');
    $check(str_contains($html, $dateLine('2027-01-05', '2027/01/05 1/5')), 'Today date must be padded only where the format says so.');
    $html = $inJapanese(static fn (): string => (new Renderer($source))->renderToday(new DateTimeImmutable('2026-11-14 15:30', new DateTimeZone('UTC')), 'n月j日(D) H:i'));
    $check(str_contains($html, $dateLine('2026-11-15', '11月15日(日) 00:30')) && str_contains($html, '<strong>Zebra</strong>'), 'Today must be judged in Asia/Tokyo.');
    $html = (new Renderer($source))->renderToday(new DateTimeImmutable('2026-11-15 12:00', $tokyo), '<b>n/j</b>');
    $check(str_contains($html, $dateLine('2026-11-15', '&lt;b&gt;11/15&lt;/b&gt;')), 'Today date was not escaped.');
    $html = (new Renderer($source, false, '<b>前回の予定</b>'))->renderToday(new DateTimeImmutable('2026-11-15 12:00', $tokyo), 'n/j');
    $check(str_contains($html, '<p class="museum-calendar-today__notice" role="status">&lt;b&gt;前回の予定&lt;/b&gt;</p>'), 'Stale notice is missing or unescaped in today.');

    $months = [];
    $edit = static function (array $events, string $month) use (&$months): array {
        $months[] = $month;
        [$zebra, , $alpha] = $events['2026-11-15'];
        $events['2026-11-15'] = [$alpha, ['title' => 'Zulu'] + $zebra];
        $events['2026-11-16'] = [];
        return $events;
    };
    add_filter('museum_calendar_events', $edit, 10, 2);
    try {
        $html = (new Renderer($source))->render(new Month('2026-11'), new DateTimeImmutable('2026-11-15 12:00', $tokyo));
        $check(str_contains($html, '<li data-title="Zulu"><strong>Zulu</strong><span>終日</span></li>') && !str_contains($html, 'Zebra'), 'A filter must be able to rename an event in the month.');
        $check(!str_contains($html, 'script') && !str_contains($html, 'Tomorrow'), 'A filter must be able to remove events from the month.');
        $check(strpos($html, '<strong>Alpha</strong>') < strpos($html, '<strong>Zulu</strong>'), 'A filter must be able to reorder the events of a day in the month.');
        $html = (new Renderer($source))->renderToday(new DateTimeImmutable('2026-11-15 12:00', $tokyo), 'n/j');
        $check(str_contains($html, '<strong>Zulu</strong>') && !str_contains($html, 'Zebra') && !str_contains($html, 'script'), 'A filter must be able to rename and remove events in today.');
        $check(strpos($html, '<strong>Alpha</strong>') < strpos($html, '<strong>Zulu</strong>'), 'A filter must be able to reorder the events of today.');
        $html = (new Renderer($source))->renderToday(new DateTimeImmutable('2026-11-16 12:00', $tokyo), 'n/j');
        $check(str_contains($html, '本日の予定はありません。') && !str_contains($html, '<ul'), 'A day emptied by a filter must read as a day without events.');
        $check($months === ['2026-11', '2026-11', '2026-11'], 'A filter must receive the month as YYYY-MM.');
    } finally {
        remove_filter('museum_calendar_events', $edit, 10);
    }

    $months = [];
    $clear = static function (array $events, string $month) use (&$months): array {
        $months[] = $month;
        return array_map(static fn (): array => [], $events);
    };
    add_filter('museum_calendar_events', $clear, 10, 2);
    try {
        $html = do_shortcode('[museum_calendar]');
        $check(str_contains($html, 'この月の予定はありません。') && !str_contains($html, '（サンプル）'), 'The month shortcode ignored a filter that removes every event.');
        $html = do_shortcode('[museum_calendar_today]');
        $check(str_contains($html, '本日の予定はありません。') && !str_contains($html, '（サンプル）'), 'The today shortcode ignored a filter that removes every event.');
        $check($months === [$now->format('Y-m'), $now->format('Y-m')], 'The shortcodes must pass the displayed month to the filter.');
    } finally {
        remove_filter('museum_calendar_events', $clear, 10);
    }

    add_filter('museum_calendar_events', '__return_null');
    try {
        $html = do_shortcode('[museum_calendar]');
        $check(str_contains($html, 'この月の予定を表示できません') && !str_contains($html, '予定はありません') && !str_contains($html, '<table'), 'An invalid filter result must not read as an empty month.');
        $html = do_shortcode('[museum_calendar_today]');
        $check(str_contains($html, '本日の予定を表示できません') && !str_contains($html, '予定はありません') && !str_contains($html, '<time'), 'An invalid filter result must not read as a day without events.');
    } finally {
        remove_filter('museum_calendar_events', '__return_null');
    }
    $check(str_contains(do_shortcode('[museum_calendar_today]'), '（サンプル）'), 'A removed filter must no longer change the output.');

    $style = wp_styles()->query('museum-calendar');
    $problems = [];
    set_error_handler(static function (int $level, string $message) use (&$problems): bool {
        $problems[] = $message;
        return true;
    });
    wp_deregister_style('museum-calendar');
    try {
        $html = do_shortcode('[museum_calendar]') . do_shortcode('[museum_calendar_today]');
    } finally {
        restore_error_handler();
        wp_register_style($style->handle, $style->src, $style->deps, $style->ver, $style->args);
    }
    $check($problems === [], 'Rendering without the default stylesheet raised: ' . implode(' / ', $problems));
    $check(str_contains($html, '<table') && str_contains($html, 'museum-calendar-today__date'), 'The shortcodes must render after a theme deregisters the default stylesheet.');
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
