<?php
/** 実際のWordPressで動かす統合テスト。 */
use MuseumCalendar\Calendar\EventSource;
use MuseumCalendar\Admin\Settings;
use MuseumCalendar\Calendar\KindTable;
use MuseumCalendar\Calendar\Month;
use MuseumCalendar\Frontend\Language;
use MuseumCalendar\Frontend\Renderer;
use MuseumCalendar\Ics\FeedRepository;

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
$previousFeed = get_option(FeedRepository::CACHE, null);
$previousCalendar = get_option(\MuseumCalendar\Admin\Settings::OPTION, null);
$previousSecret = get_option(Settings::SECRET_OPTION, null);
delete_option(\MuseumCalendar\Admin\Settings::OPTION);
delete_option(Settings::SECRET_OPTION);
// 予定の種類の表の節より前の検査は、表を一度も保存していないサイトと同じく、オプションのない状態で実行する。
$previousKinds = get_option(Settings::KINDS_OPTION, null);
delete_option(Settings::KINDS_OPTION);
$previousLock = get_option(FeedRepository::LOCK, null);
$previousCron = wp_next_scheduled('museum_calendar_refresh_feed');
$previousUser = get_current_user_id();
// ショートコードはこのカレンダーを読む。毎週、月曜日は休館、火曜日から金曜日は開館とガイドツアー、土日は開館とお茶室営業を繰り返す。
$sampleCalendar = 'museum-integration@group.calendar.google.com';
$sampleIcs = implode("\r\n", [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//museum-calendar//integration//JA', 'X-WR-TIMEZONE:Asia/Tokyo',
    'BEGIN:VEVENT', 'UID:closed@integration', 'DTSTART;VALUE=DATE:20240101', 'DTEND;VALUE=DATE:20240102', 'RRULE:FREQ=WEEKLY;BYDAY=MO', 'SUMMARY:休館日', 'END:VEVENT',
    'BEGIN:VEVENT', 'UID:open@integration', 'DTSTART:20240102T103000', 'DTEND:20240102T163000', 'RRULE:FREQ=WEEKLY;BYDAY=TU,WE,TH,FR,SA,SU', 'SUMMARY:開館日', 'END:VEVENT',
    'BEGIN:VEVENT', 'UID:tour@integration', 'DTSTART:20240102T110000', 'DTEND:20240102T120000', 'RRULE:FREQ=WEEKLY;BYDAY=TU,WE,TH,FR', 'SUMMARY:ガイドツアー開催日', 'END:VEVENT',
    'BEGIN:VEVENT', 'UID:tea@integration', 'DTSTART:20240106T120000', 'DTEND:20240106T160000', 'RRULE:FREQ=WEEKLY;BYDAY=SA,SU', 'SUMMARY:お茶室営業日', 'END:VEVENT',
    'END:VCALENDAR', '',
]);
$sampleFeed = static function ($pre, $args, $url) use (&$sampleIcs) {
    return str_contains($url, 'museum-integration') ? ['response' => ['code' => 200], 'body' => $sampleIcs, 'headers' => []] : $pre;
};
add_filter('pre_http_request', $sampleFeed, 10, 3);
try {
    $check(shortcode_exists('museum_calendar') && shortcode_exists('museum_calendar_today'), 'Shortcode registration failed.');
    $check(wp_style_is('museum-calendar', 'registered'), 'Themes need the registered stylesheet handle.');
    update_option(Settings::OPTION, $sampleCalendar, false);
    $html = do_shortcode('[museum_calendar month="2024-02"]');
    $check(str_contains($html, '<caption>February 2024</caption>'), 'Requested month was not rendered.');
    $check(str_contains($html, '2024-02-29'), 'Leap day is missing.');
    $check(!str_contains($html, '2024-02-30'), 'Impossible day was rendered.');
    foreach (['休館日' => 4, '開館日' => 25, 'ガイドツアー開催日' => 17, 'お茶室営業日' => 8] as $title => $count) {
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
    $samples = match ((int) $now->format('N')) {
        1 => ['休館日'],
        6, 7 => ['開館日', 'お茶室営業日'],
        default => ['開館日', 'ガイドツアー開催日'],
    };
    foreach (['休館日', '開館日', 'ガイドツアー開催日', 'お茶室営業日'] as $title) {
        $check(str_contains($html, "<strong>{$title}</strong>") === in_array($title, $samples, true), "Today lists the wrong samples for its weekday: {$title}.");
    }
    $sampleStatus = $samples === ['休館日']
        ? '<p class="museum-calendar-today__status" data-kind="closed" style="--museum-calendar-kind-color: #f5f5f5"><strong>休館日</strong></p>'
        : '<p class="museum-calendar-today__status" data-kind="open" style="--museum-calendar-kind-color: #ffebee"><strong>開館日</strong><span>10:30–16:30</span></p>';
    $check(str_contains($html, $sampleStatus) && !str_contains($html, '<li data-title="開館日"') && !str_contains($html, '<li data-title="休館日"'), 'The sample today must show its 開館日 or 休館日 as the status, not in the list.');
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
        $check(preg_match('/<td data-weekday="1"[^>]*>\s*<time datetime="2026-12-07">7<\/time>\s*<ul>\s*<li data-title="休館日" data-kind="closed" style="--museum-calendar-kind-color: #f5f5f5">/', $html) === 1, 'The Monday sample must stay on Mondays when the week starts on Sunday.');
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
                    ['title' => 'Zebra', 'time' => '終日', 'all_day' => true, 'start' => '', 'end' => '', 'description' => '', 'url' => ''],
                    ['title' => '<script>alert(1)</script>', 'time' => '<img src=x onerror=alert(1)>', 'all_day' => false, 'start' => '', 'end' => '', 'description' => '', 'url' => ''],
                    ['title' => 'Alpha', 'time' => '13:00', 'all_day' => false, 'start' => '13:00', 'end' => '', 'description' => '', 'url' => ''],
                ],
                $month->key() . '-16' => [['title' => 'Tomorrow', 'time' => '終日', 'all_day' => true, 'start' => '', 'end' => '', 'description' => '', 'url' => '']],
            ];
        }
    };
    $currentCell = '/<td data-weekday="7" aria-current="date">\s*<time datetime="2026-11-15">/';
    $html = (new Renderer($source, KindTable::defaults()))->render(new Month('2026-11'), new DateTimeImmutable('2026-11-15 12:00', $tokyo));
    $check(!str_contains($html, '<script>'), 'Event title was not escaped.');
    $check(!str_contains($html, '<img'), 'Event time was not escaped.');
    $check(str_contains($html, '&lt;script&gt;'), 'Escaped text was lost.');
    $check(str_contains($html, '<li data-title="&lt;script&gt;alert(1)&lt;/script&gt;" data-kind="other">'), 'Event title attribute is missing or unescaped.');
    $check(str_contains($html, '<section class="museum-calendar" id="museum-calendar"'), 'Calendar anchor is missing.');
    $check(substr_count($html, 'aria-current="date"') === 1 && preg_match($currentCell, $html) === 1, 'Today must be the only current cell.');
    preg_match_all('/<a href="([^"]*)"/', $html, $links);
    $check(count($links[1]) === 2, 'Month navigation is missing.');
    $check(str_contains($links[1][0], 'museum_month=2026-10') && str_ends_with($links[1][0], '#museum-calendar'), 'Previous month link must return to the calendar.');
    $check(str_contains($links[1][1], 'museum_month=2026-12') && str_ends_with($links[1][1], '#museum-calendar'), 'Next month link must return to the calendar.');
    $html = (new Renderer($source, KindTable::defaults()))->render(new Month('2026-11'), new DateTimeImmutable('2026-12-01 00:00', $tokyo));
    $check(!str_contains($html, 'aria-current'), 'A month without today must have no current cell.');
    $html = (new Renderer($source, KindTable::defaults()))->render(new Month('2026-11'), new DateTimeImmutable('2026-11-14 15:30', new DateTimeZone('UTC')));
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
        => (new Renderer($source, KindTable::defaults()))->render(new Month($month), new DateTimeImmutable('2026-11-15 12:00', $tokyo), $showNavigation);
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

    $html = (new Renderer($source, KindTable::defaults()))->renderToday(new DateTimeImmutable('2026-11-15 12:00', $tokyo), 'n/j');
    $check(str_contains($html, $dateLine('2026-11-15', '11/15')), 'Today date is wrong on a Sunday.');
    $order = array_map(static fn (string $item) => strpos($html, $item), ['<strong>Zebra</strong>', '<strong>&lt;script&gt;alert(1)&lt;/script&gt;</strong>', '<strong>Alpha</strong>']);
    $check(!in_array(false, $order, true) && $order[0] < $order[1] && $order[1] < $order[2], 'Today must list its events in source order.');
    $check(!str_contains($html, '<script>') && !str_contains($html, '<img'), 'Today event was not escaped.');
    $check(str_contains($html, '<li data-title="&lt;script&gt;alert(1)&lt;/script&gt;" data-kind="other">'), 'Today event title attribute is missing or unescaped.');
    $check(!str_contains($html, 'Tomorrow'), 'Today must not list another day.');
    $check(!str_contains($html, '本日の予定はありません') && !str_contains($html, 'role="status"'), 'Today showed a message that does not apply.');
    $html = (new Renderer($source, KindTable::defaults()))->renderToday(new DateTimeImmutable('2026-11-14 12:00', $tokyo), 'Y年n月j日');
    $check(str_contains($html, $dateLine('2026-11-14', '2026年11月14日')), 'Today date is wrong on a Saturday.');
    $check(str_contains($html, '本日の予定はありません。') && !str_contains($html, '<ul') && !str_contains($html, '__status'), 'A day without events must say so and show no status.');
    $html = $inJapanese(static fn (): string => (new Renderer($source, KindTable::defaults()))->renderToday(new DateTimeImmutable('2026-11-16 12:00', $tokyo), 'n月j日(D)'));
    $check(str_contains($html, $dateLine('2026-11-16', '11月16日(月)')) && str_contains($html, '<strong>Tomorrow</strong>'), 'Today is wrong on a Monday.');
    $html = (new Renderer($source, KindTable::defaults()))->renderToday(new DateTimeImmutable('2027-01-05 12:00', $tokyo), 'Y/m/d n/j');
    $check(str_contains($html, $dateLine('2027-01-05', '2027/01/05 1/5')), 'Today date must be padded only where the format says so.');
    $html = $inJapanese(static fn (): string => (new Renderer($source, KindTable::defaults()))->renderToday(new DateTimeImmutable('2026-11-14 15:30', new DateTimeZone('UTC')), 'n月j日(D) H:i'));
    $check(str_contains($html, $dateLine('2026-11-15', '11月15日(日) 00:30')) && str_contains($html, '<strong>Zebra</strong>'), 'Today must be judged in Asia/Tokyo.');
    $html = (new Renderer($source, KindTable::defaults()))->renderToday(new DateTimeImmutable('2026-11-15 12:00', $tokyo), '<b>n/j</b>');
    $check(str_contains($html, $dateLine('2026-11-15', '&lt;b&gt;11/15&lt;/b&gt;')), 'Today date was not escaped.');
    $html = (new Renderer($source, KindTable::defaults(), '<b>前回の予定</b>'))->renderToday(new DateTimeImmutable('2026-11-15 12:00', $tokyo), 'n/j');
    $check(str_contains($html, '<p class="museum-calendar-today__notice" role="status">&lt;b&gt;前回の予定&lt;/b&gt;</p>'), 'Stale notice is missing or unescaped in today.');

    $japaneseText = ['月間カレンダー', '本日の予定', '月間予定表', '予定はありません', '終日', '正しくありません', '表示できません', '設定されていません', '取得'];
    $allEnglish = static function (string $html, string $where) use ($check, $japaneseText): void {
        foreach ($japaneseText as $text) {
            $check(!str_contains($html, $text), "{$where} printed the Japanese text {$text} with lang=\"en\".");
        }
    };
    $monthSection = '<section class="museum-calendar" id="museum-calendar" aria-label="%s"%s>' . "\n";
    $todaySection = '<section class="museum-calendar-today" aria-label="%s"%s>' . "\n";
    $html = do_shortcode('[museum_calendar month="2024-02" lang="en"]');
    $check(str_starts_with($html, sprintf($monthSection, 'Monthly calendar', ' lang="en"')), 'An English month must declare its language on the section.');
    $check(str_contains($html, '<div class="museum-calendar__scroll" tabindex="0" role="region" aria-label="Monthly schedule (scrolls sideways)">'), 'An English month must label its scroll region in English.');
    $check(str_contains($html, '<caption>February 2024</caption>') && substr_count($html, '<time datetime=') === 29, 'An English month must show the requested month.');
    $check(substr_count($html, '<li data-title="休館日" data-kind="closed" style="--museum-calendar-kind-color: #f5f5f5"><strong>CLOSED</strong><span>All day</span></li>') === 4, 'An English month must print the English text of a sample title next to the English all-day time.');
    $check(substr_count($html, '<li data-title="開館日" data-kind="open" style="--museum-calendar-kind-color: #ffebee"><strong>OPEN</strong><span>10:30–16:30</span></li>') === 25, 'An English month must leave a time with hours as it is.');
    $allEnglish($html, 'The month');
    $html = do_shortcode('[museum_calendar month="2024-02"]');
    $check(str_starts_with($html, sprintf($monthSection, '月間カレンダー', '')) && !str_contains($html, ' lang='), 'A month without lang must keep its Japanese text and declare no language.');
    $check(str_contains($html, '<div class="museum-calendar__scroll" tabindex="0" role="region" aria-label="月間予定表（横にスクロールできます）">'), 'A month without lang must keep the Japanese label of its scroll region.');
    $check(substr_count($html, '<li data-title="休館日" data-kind="closed" style="--museum-calendar-kind-color: #f5f5f5"><strong>休館日</strong><span>終日</span></li>') === 4, 'A month without lang must keep the markup of its events.');
    $check(do_shortcode('[museum_calendar month="2024-02" lang=""]') === $html, 'An empty lang must render as no lang.');

    $dateFormat = get_option('date_format');
    $startOfWeek = get_option('start_of_week');
    try {
        update_option('date_format', 'n月j日(D)');
        update_option('start_of_week', 1);
        $html = do_shortcode('[museum_calendar_today lang="en"]');
        $check(str_starts_with($html, sprintf($todaySection, 'Today&#039;s schedule', ' lang="en"')), 'An English today must declare its language on the section.');
        $check(str_contains($html, $dateLine($today, $now->format('D, M j'))), 'An English today must print the date as D, M j, whatever the date format is.');
        $english = ['ガイドツアー開催日' => 'Guided Tour', 'お茶室営業日' => 'Tea Room Open'];
        foreach (array_diff($samples, ['開館日', '休館日']) as $title) {
            $check(str_contains($html, '<li data-title="' . $title . '" data-kind="' . KindTable::defaults()->kind($title) . '" style="--museum-calendar-kind-color: ' . KindTable::defaults()->color($title) . '"><strong>' . $english[$title] . '</strong><span>'), "An English today must print the English text of {$title}.");
        }
        $check(str_contains($html, str_replace(['<strong>休館日</strong>', '<strong>開館日</strong>'], ['<strong>CLOSED</strong>', '<strong>OPEN</strong>'], $sampleStatus)), 'An English today must print the status of the sample in English.');
        $allEnglish($html, 'Today');
        $html = do_shortcode('[museum_calendar_today]');
        $check(str_starts_with($html, sprintf($todaySection, '本日の予定', '')) && !str_contains($html, ' lang='), 'A today without lang must keep its Japanese text and declare no language.');
        $check(str_contains($html, $dateLine($today, wp_date('n月j日(D)', $now->getTimestamp(), $tokyo))), 'A today without lang must keep the WordPress date format.');
        $check(do_shortcode('[museum_calendar_today lang=""]') === $html, 'An empty lang must render today as no lang.');

        $previousMonth = $now->modify('first day of previous month');
        $nextMonth = $now->modify('first day of next month');
        $englishHeader = [
            'nav' => 'Previous and next months',
            'links' => [$previousMonth->format('Y-m') . ' &laquo; ' . $previousMonth->format('F'), $nextMonth->format('Y-m') . ' ' . $nextMonth->format('F') . ' &raquo;'],
            'caption' => $now->format('F Y'),
        ];
        $japaneseHeader = [
            'nav' => 'Previous and next months',
            'links' => [$previousMonth->format('Y-m') . ' &laquo; ' . $previousMonth->format('n') . '月', $nextMonth->format('Y-m') . ' ' . $nextMonth->format('n') . '月 &raquo;'],
            'caption' => $now->format('n') . '月 ' . $now->format('Y'),
        ];
        $japaneseDate = $dateLine($today, $now->format('n月j日') . '(' . $weekdayNames[(int) $now->format('w')] . ')');
        $seen = [];
        $inJapanese(static function () use (&$seen): string {
            $japanese = static fn (): string => do_shortcode('[museum_calendar]') . do_shortcode('[museum_calendar_today]');
            $seen['before'] = $japanese();
            $seen['month'] = do_shortcode('[museum_calendar lang="en"]');
            $seen['locale after month'] = determine_locale();
            $seen['today'] = do_shortcode('[museum_calendar_today lang="en"]');
            $seen['locale after today'] = determine_locale();
            $seen['after'] = $japanese();
            return '';
        });
        $check($header($seen['before']) === $japaneseHeader && $grid($seen['before'])['labels'] === '月 火 水 木 金 土 日' && str_contains($seen['before'], $japaneseDate), 'The Japanese site of the tests must print Japanese names.');
        $check($header($seen['month']) === $englishHeader, 'An English month on a Japanese site must print its heading, links and navigation label in English.');
        $check($grid($seen['month'])['labels'] === 'Mon Tue Wed Thu Fri Sat Sun', 'An English month on a Japanese site must print English weekday names.');
        $check(str_contains($seen['today'], $dateLine($today, $now->format('D, M j'))), 'An English today on a Japanese site must print English weekday and month names.');
        $allEnglish($seen['month'], 'The month on a Japanese site');
        $allEnglish($seen['today'], 'Today on a Japanese site');
        $check($seen['locale after month'] === 'ja' && $seen['locale after today'] === 'ja', 'An English shortcode must restore the locale of the site.');
        $check($seen['after'] === $seen['before'], 'A shortcode without lang must print the same after an English shortcode.');
        $check(determine_locale() === 'en_US' && !is_locale_switched(), 'The test site must be back in its own locale.');
        $check($header(do_shortcode('[museum_calendar lang="en"]')) === $englishHeader && !is_locale_switched(), 'An English month on an English site must render without a locale switch left behind.');

        $sample = (new DateTimeImmutable('2026-11-15 12:00', $tokyo))->getTimestamp();
        $names = static fn (): string => wp_date('D, F', $sample, $tokyo);
        $inJapanese(static function () use ($check, $names): string {
            $check($names() === '日, 11月', 'The Japanese site of the tests must name weekdays and months in Japanese.');
            $check(Language::English->withLocale($names) === 'Sun, November', 'English must run its rendering in the English locale.');
            $check(Language::Japanese->withLocale($names) === '日, 11月', 'Japanese must run its rendering in the locale of the site.');
            $failure = null;
            try {
                Language::English->withLocale(static function (): string {
                    throw new RuntimeException('The rendering failed.');
                });
            } catch (RuntimeException $error) {
                $failure = $error->getMessage();
            }
            $check($failure === 'The rendering failed.', 'A failed rendering must reach the caller.');
            $check(determine_locale() === 'ja' && $names() === '日, 11月', 'A failed English rendering must restore the locale of the site.');
            $check(switch_to_locale('en_US'), 'The test could not switch to en_US.');
            try {
                $html = do_shortcode('[museum_calendar_today lang="en"]');
                $check(str_contains($html, 'lang="en"') && determine_locale() === 'en_US', 'An English shortcode must leave a locale switch of other code in place.');
            } finally {
                restore_previous_locale();
            }
            $check(determine_locale() === 'ja', 'The test did not restore its own locale switch.');
            return '';
        });
    } finally {
        update_option('date_format', $dateFormat);
        update_option('start_of_week', $startOfWeek);
    }

    $invalidLanguage = '<p class="museum-calendar-notice">言語の指定が正しくありません。</p>';
    foreach (['ja', 'EN', 'en_US', 'en-US', 'english', 'fr'] as $invalid) {
        foreach (['museum_calendar', 'museum_calendar month="2026-11"', 'museum_calendar month="invalid"', 'museum_calendar_today'] as $shortcode) {
            $check(do_shortcode("[{$shortcode} lang=\"{$invalid}\"]") === $invalidLanguage, "[{$shortcode}] accepted lang=\"{$invalid}\".");
        }
    }
    $check(do_shortcode('[museum_calendar month="invalid" lang="en"]') === '<p class="museum-calendar-notice" lang="en">The month is not valid.</p>', 'An invalid month must be reported in English.');
    $check(do_shortcode('[museum_calendar month="invalid"]') === '<p class="museum-calendar-notice">年月の指定が正しくありません。</p>', 'The notice of an invalid month changed.');

    $fromNow = static fn (int $months): string => $now->modify('first day of this month')->modify(sprintf('%+d months', $months))->format('Y-m');
    $monthLinks = static function (string $html): array {
        preg_match_all('/<a href="[^"]*[?;]museum_month=(\d{4}-\d{2})#museum-calendar">\s*(&laquo;)?/', $html, $found, PREG_SET_ORDER);
        $links = ['previous' => null, 'next' => null];
        foreach ($found as $link) {
            $links[($link[2] ?? '') === '' ? 'next' : 'previous'] = $link[1];
        }
        return $links;
    };
    $caption = static fn (string $month): string => '<caption>' . (new DateTimeImmutable($month . '-01', $tokyo))->format('F Y') . '</caption>';
    $queried = array_key_exists('museum_month', $_GET) ? $_GET['museum_month'] : null;
    $byQuery = static function (string $month, string $attributes = ''): string {
        $_GET['museum_month'] = $month;
        return do_shortcode("[museum_calendar{$attributes}]");
    };
    try {
        $check($monthLinks(do_shortcode('[museum_calendar]')) === ['previous' => $fromNow(-1), 'next' => $fromNow(1)], 'The current month must link to the previous and the next month.');
        $html = $byQuery($fromNow(1));
        $check(str_contains($html, $caption($fromNow(1))) && $monthLinks($html) === ['previous' => $fromNow(0), 'next' => $fromNow(2)], 'The next month must link to the month after next.');
        $html = $byQuery($fromNow(2));
        $check(str_contains($html, $caption($fromNow(2))) && $monthLinks($html) === ['previous' => $fromNow(1), 'next' => null], 'The month after next must render with a previous link and no next link.');
        $check(str_contains($byQuery($fromNow(2), ' lang="en"'), $caption($fromNow(2))), 'An English month after next must render.');
        $future = '<p class="museum-calendar-notice">この月の予定はまだ表示できません。</p>';
        $check($byQuery($fromNow(3)) === $future, 'A month after the month after next must show only the notice.');
        $check($byQuery($fromNow(14)) === $future, 'A month a year ahead must show only the notice.');
        $check($byQuery($fromNow(3), ' lang="en"') === '<p class="museum-calendar-notice" lang="en">This month cannot be shown yet.</p>', 'The notice of a later month must be in English with lang="en".');
        $check($byQuery('2026-13') === '<p class="museum-calendar-notice">年月の指定が正しくありません。</p>', 'An invalid query month must keep the notice of an invalid month.');
        $html = $byQuery('1950-06');
        $check(str_contains($html, $caption('1950-06')) && $monthLinks($html) === ['previous' => '1950-05', 'next' => '1950-07'], 'A month far in the past must render with both links.');
        $html = $byQuery($fromNow(3), ' month="' . $fromNow(6) . '"');
        $check(str_contains($html, $caption($fromNow(6))) && !str_contains($html, 'museum-calendar__nav') && !str_contains($html, 'まだ表示できません'), 'A fixed month must render whatever its distance from today, without navigation.');
    } finally {
        if ($queried === null) {
            unset($_GET['museum_month']);
        } else {
            $_GET['museum_month'] = $queried;
        }
    }

    $sampleEvents = $sampleIcs;
    $sampleIcs = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n";
    FeedRepository::clear();
    try {
        $html = do_shortcode('[museum_calendar lang="en"]');
        $check(str_contains($html, '<p class="museum-calendar__empty">No events this month.</p>') && !str_contains($html, '<li'), 'An English month without events must say so in English.');
        $allEnglish($html, 'The empty month');
        $html = do_shortcode('[museum_calendar_today lang="en"]');
        $check(str_contains($html, '<p class="museum-calendar-today__empty">No events today.</p>') && !str_contains($html, '<ul'), 'An English day without events must say so in English.');
        $allEnglish($html, 'The empty day');
        $check(str_contains(do_shortcode('[museum_calendar]'), '<p class="museum-calendar__empty">この月の予定はありません。</p>'), 'The message of an empty month changed.');
        $check(str_contains(do_shortcode('[museum_calendar_today]'), '<p class="museum-calendar-today__empty">本日の予定はありません。</p>'), 'The message of an empty day changed.');
    } finally {
        $sampleIcs = $sampleEvents;
        FeedRepository::clear();
    }
    delete_option(Settings::OPTION);
    try {
        foreach (['museum_calendar', 'museum_calendar_today'] as $shortcode) {
            $check(do_shortcode("[{$shortcode} lang=\"en\"]") === '<p class="museum-calendar-notice" lang="en">The calendar connection is not set up.</p>', "[{$shortcode}] must report a missing connection in English.");
            $check(do_shortcode("[{$shortcode}]") === '<p class="museum-calendar-notice">カレンダーの接続が設定されていません。</p>', "The notice of [{$shortcode}] without a connection changed.");
        }
    } finally {
        update_option(Settings::OPTION, $sampleCalendar, false);
    }

    $facility = new class implements EventSource {
        public function forMonth(Month $month): array
        {
            $allDay = static fn (string $title): array => ['title' => $title, 'time' => '終日', 'all_day' => true, 'start' => '', 'end' => '', 'description' => '', 'url' => ''];
            $timed = static fn (string $title): array => ['title' => $title, 'time' => '10:00–16:00', 'all_day' => false, 'start' => '10:00', 'end' => '16:00', 'description' => '', 'url' => ''];
            return [
                $month->key() . '-14' => [$allDay('休館日')],
                $month->key() . '-15' => [
                    $timed('開館日'),
                    $timed('お茶室営業日'),
                    $timed(' ガイドツアー開催日 '),
                    $allDay('結婚式'),
                    $timed('夜間特別開館'),
                    $timed('コンサート'),
                    $timed('　イベント'),
                    $timed('臨時休館'),
                    $timed('<b>特別展</b>'),
                    $timed('（無題）'),
                ],
            ];
        }
    };
    $sunday = new DateTimeImmutable('2026-11-15 12:00', $tokyo);
    $closed = '<li data-title="休館日" data-kind="closed" style="--museum-calendar-kind-color: #f5f5f5"><strong>CLOSED</strong><span>All day</span></li>';
    $englishItems = [
        '<li data-title="開館日" data-kind="open" style="--museum-calendar-kind-color: #ffebee"><strong>OPEN</strong><span>10:00–16:00</span></li>',
        '<li data-title="お茶室営業日" data-kind="tea" style="--museum-calendar-kind-color: #e8f5e9"><strong>Tea Room Open</strong><span>10:00–16:00</span></li>',
        '<li data-title=" ガイドツアー開催日 " data-kind="tour" style="--museum-calendar-kind-color: #e3f2fd"><strong>Guided Tour</strong><span>10:00–16:00</span></li>',
        '<li data-title="結婚式" data-kind="wedding" style="--museum-calendar-kind-color: #fff0fc"><strong>Wedding</strong><span>All day</span></li>',
        '<li data-title="夜間特別開館" data-kind="evening" style="--museum-calendar-kind-color: #fff9f4"><strong>Special Evening Opening</strong><span>10:00–16:00</span></li>',
        '<li data-title="コンサート" data-kind="concert" style="--museum-calendar-kind-color: #f5f5f5"><strong>Concert</strong><span>10:00–16:00</span></li>',
        '<li data-title="　イベント" data-kind="event" style="--museum-calendar-kind-color: #f5f5f5"><strong>Event</strong><span>10:00–16:00</span></li>',
        '<li data-title="臨時休館" data-kind="other"><strong lang="ja">臨時休館</strong><span>10:00–16:00</span></li>',
        '<li data-title="&lt;b&gt;特別展&lt;/b&gt;" data-kind="other"><strong lang="ja">&lt;b&gt;特別展&lt;/b&gt;</strong><span>10:00–16:00</span></li>',
        '<li data-title="（無題）" data-kind="other"><strong>(Untitled)</strong><span>10:00–16:00</span></li>',
    ];
    $japaneseItems = [
        '<li data-title="休館日" data-kind="closed" style="--museum-calendar-kind-color: #f5f5f5"><strong>休館日</strong><span>終日</span></li>',
        '<li data-title="開館日" data-kind="open" style="--museum-calendar-kind-color: #ffebee"><strong>開館日</strong><span>10:00–16:00</span></li>',
        '<li data-title="お茶室営業日" data-kind="tea" style="--museum-calendar-kind-color: #e8f5e9"><strong>お茶室営業日</strong><span>10:00–16:00</span></li>',
        '<li data-title=" ガイドツアー開催日 " data-kind="tour" style="--museum-calendar-kind-color: #e3f2fd"><strong> ガイドツアー開催日 </strong><span>10:00–16:00</span></li>',
        '<li data-title="結婚式" data-kind="wedding" style="--museum-calendar-kind-color: #fff0fc"><strong>結婚式</strong><span>終日</span></li>',
        '<li data-title="夜間特別開館" data-kind="evening" style="--museum-calendar-kind-color: #fff9f4"><strong>夜間特別開館</strong><span>10:00–16:00</span></li>',
        '<li data-title="コンサート" data-kind="concert" style="--museum-calendar-kind-color: #f5f5f5"><strong>コンサート</strong><span>10:00–16:00</span></li>',
        '<li data-title="　イベント" data-kind="event" style="--museum-calendar-kind-color: #f5f5f5"><strong>　イベント</strong><span>10:00–16:00</span></li>',
        '<li data-title="臨時休館" data-kind="other"><strong>臨時休館</strong><span>10:00–16:00</span></li>',
        '<li data-title="&lt;b&gt;特別展&lt;/b&gt;" data-kind="other"><strong>&lt;b&gt;特別展&lt;/b&gt;</strong><span>10:00–16:00</span></li>',
        '<li data-title="（無題）" data-kind="other"><strong>（無題）</strong><span>10:00–16:00</span></li>',
    ];
    $listed = static function (string $html, array $items, string $where) use ($check): void {
        $check(substr_count($html, '<li') === count($items), "{$where} lists the wrong number of events.");
        $offset = 0;
        foreach ($items as $item) {
            $position = strpos($html, $item, $offset);
            $check($position !== false, "{$where} must print, in order: {$item}");
            $offset = $position + strlen($item);
        }
    };
    $html = (new Renderer($facility, KindTable::defaults(), '', Language::English))->render(new Month('2026-11'), $sunday);
    $listed($html, [$closed, ...$englishItems], 'The English month');
    $check(!str_contains($html, '<b>'), 'A title without an English text was not escaped.');
    $html = (new Renderer($facility, KindTable::defaults(), '', Language::English))->renderToday($sunday, 'D, M j');
    $listed($html, array_slice($englishItems, 1), 'The English today');
    $check(str_contains($html, $dateLine('2026-11-15', 'Sun, Nov 15') . "\n" . '<p class="museum-calendar-today__status" data-kind="open" style="--museum-calendar-kind-color: #ffebee"><strong>OPEN</strong><span>10:00–16:00</span></p>'), 'The English today must print the status of its 開館日, with its hours, after the date.');
    $html = (new Renderer($facility, KindTable::defaults()))->render(new Month('2026-11'), $sunday);
    $listed($html, $japaneseItems, 'The month without lang');
    $check(!str_contains($html, ' lang='), 'A month without lang must mark no event as Japanese.');
    $html = (new Renderer($facility, KindTable::defaults()))->renderToday($sunday, 'n/j');
    $listed($html, array_slice($japaneseItems, 2), 'Today without lang');
    $check(str_contains($html, '<p class="museum-calendar-today__status" data-kind="open" style="--museum-calendar-kind-color: #ffebee"><strong>開館日</strong><span>10:00–16:00</span></p>'), 'Today without lang must print the status of its 開館日.');
    $check(!str_contains($html, ' lang='), 'A today without lang must mark no event as Japanese.');
    $status = static function (string $html): ?string {
        return preg_match('/<p class="museum-calendar-today__status"[^>]*>.*?<\/p>/', $html, $match) === 1 ? $match[0] : null;
    };
    $closedStatus = '<p class="museum-calendar-today__status" data-kind="closed" style="--museum-calendar-kind-color: #f5f5f5"><strong>%s</strong></p>';
    $html = (new Renderer($facility, KindTable::defaults(), '', Language::English))->renderToday(new DateTimeImmutable('2026-11-14 12:00', $tokyo), 'D, M j');
    $check($status($html) === sprintf($closedStatus, 'CLOSED'), 'An English today with only 休館日 must show CLOSED.');
    $check(!str_contains($html, '<ul') && !str_contains($html, '__empty') && !str_contains($html, 'No events'), 'A today with only 休館日 must print the status alone.');
    $check($status((new Renderer($facility, KindTable::defaults()))->renderToday(new DateTimeImmutable('2026-11-14 12:00', $tokyo), 'n/j')) === sprintf($closedStatus, '休館日'), 'A today without lang must show the registered title 休館日.');
    $html = (new Renderer($facility, KindTable::defaults()))->renderToday(new DateTimeImmutable('2026-11-16 12:00', $tokyo), 'n/j');
    $check($status($html) === null && str_contains($html, '本日の予定はありません。'), 'A day without 開館日 and 休館日 must show no status, and must not read as closed.');
    $openStatus = '<p class="museum-calendar-today__status" data-kind="open" style="--museum-calendar-kind-color: #ffebee"><strong>開館日</strong><span>10:00–16:00</span></p>';
    foreach ([
        ['2026-11-14 23:59:59', $tokyo, sprintf($closedStatus, '休館日')],
        ['2026-11-15 00:00:00', $tokyo, $openStatus],
        ['2026-11-15 23:59:59', $tokyo, $openStatus],
        ['2026-11-16 00:00:00', $tokyo, null],
        ['2026-11-14 14:59:59', new DateTimeZone('UTC'), sprintf($closedStatus, '休館日')],
        ['2026-11-14 15:00:00', new DateTimeZone('UTC'), $openStatus],
    ] as [$time, $zone, $expected]) {
        $check($status((new Renderer($facility, KindTable::defaults()))->renderToday(new DateTimeImmutable($time, $zone), 'n/j')) === $expected, "The status at {$time} {$zone->getName()} must be that of the day in Asia/Tokyo.");
    }
    $html = (new Renderer($facility, KindTable::defaults(), 'Shown <b>earlier</b>', Language::English))->renderToday($sunday, 'D, M j');
    $check(str_starts_with($html, sprintf($todaySection, 'Today&#039;s schedule', ' lang="en"') . '<p class="museum-calendar-today__notice" role="status">Shown &lt;b&gt;earlier&lt;/b&gt;</p>' . "\n"), 'An English today must print the notice inside its section.');

    // 予定の種類の表。ここまではオプションがなく、ここより上の出力は表を保存していないサイトのもの。
    $check(get_option(Settings::KINDS_OPTION, null) === null && Settings::kinds()->rows() === KindTable::DEFAULTS, 'A site without the option must use the default table.');
    $kindPages = static fn (): array => [
        'month' => do_shortcode('[museum_calendar month="2024-02"]'),
        'english month' => do_shortcode('[museum_calendar month="2024-02" lang="en"]'),
        'today' => do_shortcode('[museum_calendar_today]'),
        'english today' => do_shortcode('[museum_calendar_today lang="en"]'),
    ];
    $defaultPages = $kindPages();
    $check(substr_count($defaultPages['english month'], '<li data-title="ガイドツアー開催日" data-kind="tour" style="--museum-calendar-kind-color: #e3f2fd"><strong>Guided Tour</strong><span>11:00–12:00</span></li>') === 17, 'The default table must give the sample tours their kind, color and English text.');
    $check(substr_count($defaultPages['month'], '<li data-title="開館日" data-kind="open" style="--museum-calendar-kind-color: #ffebee">') === 25 && substr_count($defaultPages['month'], '<li data-title="休館日" data-kind="closed" style="--museum-calendar-kind-color: #f5f5f5">') === 4, 'The default table must give the sample events their colors.');
    update_option(Settings::KINDS_OPTION, KindTable::DEFAULTS, false);
    $check($kindPages() === $defaultPages, 'A stored default table must print the same as no table.');
    foreach (['corrupt', 42, null, ['title' => '開館日'], [['title' => '開館日', 'kind' => 'other', 'english' => '', 'color' => '']], [['title' => '開館日', 'kind' => 'open', 'english' => '', 'color' => 'red']], [['title' => '開館日', 'kind' => 'open', 'english' => '', 'color' => null]], [['title' => '開館日', 'kind' => 'open', 'english' => '']], [['title' => '開館日', 'kind' => 'open']], [['title' => '開館日', 'kind' => 'open', 'english' => '', 'color' => ''], ['title' => '開館日', 'kind' => 'closed', 'english' => '', 'color' => '']]] as $corrupt) {
        update_option(Settings::KINDS_OPTION, $corrupt, false);
        $check(Settings::kinds()->rows() === KindTable::DEFAULTS && $kindPages() === $defaultPages, 'A corrupt kind table must fall back to the defaults: ' . var_export($corrupt, true));
    }
    delete_option(Settings::KINDS_OPTION);

    $custom = [
        ['title' => '開館日', 'kind' => 'open', 'english' => 'OPEN', 'color' => ''],
        ['title' => '休館日', 'kind' => 'closed', 'english' => '', 'color' => ''],
        ['title' => 'お茶室営業日', 'kind' => 'tea-room', 'english' => 'Tea House', 'color' => ''],
        ['title' => 'ガイドツアー開催日', 'kind' => 'closed', 'english' => 'Closed for a Tour', 'color' => ''],
        ['title' => '臨時休館', 'kind' => 'closed', 'english' => 'Closed Today', 'color' => ''],
    ];
    wp_set_current_user(0);
    $check(is_wp_error(Settings::saveKinds(['kinds' => $custom])) && get_option(Settings::KINDS_OPTION, null) === null, 'A visitor must not save the kind table.');
    require_once ABSPATH . 'wp-admin/includes/user.php';
    $editor = get_user_by('login', 'museum-kinds-editor');
    $editorId = $editor ? $editor->ID : wp_insert_user(['user_login' => 'museum-kinds-editor', 'user_pass' => wp_generate_password(), 'role' => 'editor']);
    try {
        wp_set_current_user($editorId);
        $check(!current_user_can('manage_options') && is_wp_error(Settings::saveKinds(['kinds' => $custom])) && get_option(Settings::KINDS_OPTION, null) === null, 'A user without manage_options must not save the kind table.');
    } finally {
        wp_delete_user($editorId);
    }
    wp_set_current_user(1);
    $calendar = get_option(Settings::OPTION, null);
    $blank = ['title' => '', 'kind' => '', 'english' => '', 'color' => ''];
    $form = [['title' => ' 開館日 ', 'kind' => 'open', 'english' => ' OPEN ', 'color' => ' '], ...array_slice($custom, 1)];
    $check(Settings::saveKinds(['kinds' => [5 => $form[0], 7 => $form[1], 9 => $blank, 10 => $form[2], 11 => $form[3], 12 => ['title' => '　', 'kind' => 'x!', 'english' => '', 'color' => 'red'], 13 => $form[4], 14 => $blank]]) === true, 'A valid kind table was rejected.');
    $check(get_option(Settings::KINDS_OPTION) === $custom, 'The kind table must be stored trimmed, in the order of the form, without the rows without a title.');
    global $wpdb;
    $check(in_array($wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Settings::KINDS_OPTION)), ['no', 'off'], true), 'The kind table must not be autoloaded.');
    $check(get_option(Settings::OPTION, null) === $calendar, 'Saving the kind table changed the calendar.');

    $pages = $kindPages();
    $check(substr_count($pages['english month'], '<li data-title="開館日" data-kind="open"><strong>OPEN</strong><span>10:30–16:30</span></li>') === 8, 'A title saved as closed must hide the 開館日 of its day.');
    $check(substr_count($pages['english month'], '<li data-title="ガイドツアー開催日" data-kind="closed"><strong>Closed for a Tour</strong><span>11:00–12:00</span></li>') === 17, 'A saved row must give its title its kind and English text.');
    $check(substr_count($pages['english month'], '<li data-title="お茶室営業日" data-kind="tea-room"><strong>Tea House</strong><span>12:00–16:00</span></li>') === 8, 'A new kind must reach data-kind.');
    $check(substr_count($pages['english month'], '<li data-title="休館日" data-kind="closed"><strong lang="ja">休館日</strong><span>All day</span></li>') === 4, 'A row without English must keep its kind and print its title as Japanese.');
    $check(substr_count($pages['month'], 'data-kind="closed"><strong>ガイドツアー開催日</strong>') === 17 && substr_count($pages['month'], '<li data-title="開館日"') === 8, 'The month without lang must follow the saved table.');
    $todayStatus = static fn (string $kind, string $label, string $hours = ''): string
        => '<p class="museum-calendar-today__status" data-kind="' . $kind . '"><strong>' . $label . '</strong>' . ($hours === '' ? '' : '<span>' . $hours . '</span>') . '</p>';
    if ($samples === ['休館日']) {
        $check(str_contains($pages['english today'], '<p class="museum-calendar-today__status" data-kind="closed"><strong lang="ja">休館日</strong></p>'), 'An English today must show a closed title without English as it is, marked as Japanese.');
    } elseif (in_array('お茶室営業日', $samples, true)) {
        $check(str_contains($pages['english today'], $todayStatus('open', 'OPEN', '10:30–16:30')) && str_contains($pages['english today'], '<li data-title="お茶室営業日" data-kind="tea-room"><strong>Tea House</strong><span>12:00–16:00</span></li>'), 'An English today must list a saved kind with its English text.');
    } else {
        $check(str_contains($pages['english today'], $todayStatus('closed', 'Closed for a Tour')) && str_contains($pages['today'], $todayStatus('closed', 'ガイドツアー開催日')), 'A title saved as closed must be the status of today.');
        $check(!str_contains($pages['english today'], '<ul') && !str_contains($pages['english today'], '10:30–16:30'), 'A title saved as closed must hide the 開館日 of today.');
    }
    $html = (new Renderer($facility, Settings::kinds(), '', Language::English))->renderToday($sunday, 'D, M j');
    // 15日の closed の予定のうちガイドツアーが最初に来るので、それが開館状況になる。
    $check($status($html) === $todayStatus('closed', 'Closed for a Tour') && !str_contains($html, 'data-title="開館日"') && !str_contains($html, 'data-title="臨時休館"'), 'A second title for closed must win over 開館日.');
    $html = (new Renderer($facility, Settings::kinds(), '', Language::English))->render(new Month('2026-11'), $sunday);
    $check(str_contains($html, '<li data-title="臨時休館" data-kind="closed"><strong>Closed Today</strong>') && !str_contains($html, 'data-title="開館日"'), 'A saved closed title must hide the 開館日 of its day in the month.');
    $check(str_contains($html, '<li data-title="結婚式" data-kind="other"><strong lang="ja">結婚式</strong>'), 'A default row that the saved table dropped must be other.');
    $html = (new Renderer($facility, Settings::kinds(), '', Language::English))->renderToday(new DateTimeImmutable('2026-11-14 12:00', $tokyo), 'D, M j');
    $check($status($html) === '<p class="museum-calendar-today__status" data-kind="closed"><strong lang="ja">休館日</strong></p>', 'An English status from a row without English must mark its label as Japanese.');
    $check($status((new Renderer($facility, Settings::kinds()))->renderToday(new DateTimeImmutable('2026-11-14 12:00', $tokyo), 'n/j')) === '<p class="museum-calendar-today__status" data-kind="closed"><strong>休館日</strong></p>', 'A status without lang must not mark its label.');

    foreach ([
        'duplicate_title' => [...$custom, ['title' => '　開館日', 'kind' => 'event', 'english' => '', 'color' => '']],
        'invalid_kind' => [...$custom, ['title' => '講座', 'kind' => 'other', 'english' => '', 'color' => '']],
        'too_long' => [...$custom, ['title' => str_repeat('講', 201), 'kind' => 'event', 'english' => '', 'color' => '']],
        'too_many' => array_map(static fn (int $number): array => ['title' => "催し{$number}", 'kind' => 'event', 'english' => '', 'color' => ''], range(1, 101)),
        'invalid_title' => [...$custom, ['title' => "講\n座", 'kind' => 'event', 'english' => '', 'color' => '']],
        'invalid_color' => [...$custom, ['title' => '講座', 'kind' => 'event', 'english' => '', 'color' => 'red']],
        'invalid_rows' => 'OPEN',
    ] as $code => $rows) {
        $result = Settings::saveKinds(['kinds' => $rows]);
        $check(is_wp_error($result) && $result->get_error_code() === $code && $result->get_error_message() !== '', "An invalid kind table must be rejected as {$code}.");
        $check(get_option(Settings::KINDS_OPTION) === $custom, "A rejected kind table ({$code}) changed the saved one.");
    }
    $check(is_wp_error(Settings::saveKinds(['kinds' => [['title' => '開館日', 'kind' => 'open']]])) && get_option(Settings::KINDS_OPTION) === $custom, 'A row without English must be rejected.');
    foreach (['#fff', '#12345g', '#1234567', '2f6f4f', 'rgb(0, 0, 0)', '#2f6f4f;color:red'] as $color) {
        $result = Settings::saveKinds(['kinds' => [...$custom, ['title' => '講座', 'kind' => 'event', 'english' => '', 'color' => $color]]]);
        $check(is_wp_error($result) && $result->get_error_code() === 'invalid_color' && get_option(Settings::KINDS_OPTION) === $custom, "The color {$color} must be rejected without changing the saved table.");
    }
    $result = Settings::saveKinds(['kinds' => [...$custom, ['title' => '講座', 'kind' => 'event', 'english' => '', 'color' => ['#2f6f4f']]]]);
    $check(is_wp_error($result) && $result->get_error_code() === 'invalid_rows' && get_option(Settings::KINDS_OPTION) === $custom, 'A color that is not a string must be rejected as invalid_rows.');

    $check(Settings::saveKinds(['kinds' => [$blank, ['title' => ' 　', 'kind' => 'open', 'english' => 'OPEN', 'color' => '']]]) === true && get_option(Settings::KINDS_OPTION, null) === [], 'A table without rows must be stored as an empty table.');
    $pages = $kindPages();
    $check(!str_contains($pages['month'], 'data-kind="open"') && !str_contains($pages['month'], 'data-kind="closed"') && substr_count($pages['month'], '<li data-title="開館日" data-kind="other">') === 25, 'An empty table must make every title other.');
    $check(substr_count($pages['english month'], '<strong lang="ja">休館日</strong>') === 4 && !str_contains($pages['english today'], 'museum-calendar-today__status'), 'An empty table must leave every title in Japanese and show no status.');

    $check(Settings::saveKinds(['kinds' => [...array_map(static fn (array $row): array => ['title' => ' ' . $row['title'], 'english' => $row['english'] . '　'] + $row, KindTable::DEFAULTS), $blank, $blank]]) === true, 'The default table was rejected.');
    $check(get_option(Settings::KINDS_OPTION, null) === null, 'Saving the default table must delete the option.');
    Settings::saveKinds(['kinds' => $custom]);
    $check(Settings::saveKinds(['reset' => '1', 'kinds' => [['title' => '開館日', 'kind' => 'other', 'english' => '']]]) === true && get_option(Settings::KINDS_OPTION, null) === null, 'A reset must delete the option and ignore the rows.');
    $check($kindPages() === $defaultPages && get_option(Settings::OPTION, null) === $calendar, 'After a reset the site must print as with the default table, and keep its calendar.');

    // 色。大文字と前後の空白は受け付け、小文字で保存する。色を空にすると、既定の行の色がなくなる。
    $colored = array_map(static fn (array $row): array => match ($row['title']) {
        '休館日' => array_replace($row, ['color' => ' #E26B6B ']),
        '開館日' => array_replace($row, ['color' => '']),
        default => $row,
    }, KindTable::DEFAULTS);
    $check(Settings::saveKinds(['kinds' => [...$colored, $blank]]) === true, 'A kind table with colors was rejected.');
    $stored = get_option(Settings::KINDS_OPTION, null);
    $check(is_array($stored) && array_column($stored, 'color', 'title') === ['開館日' => '', '休館日' => '#e26b6b', 'お茶室営業日' => '#e8f5e9', 'ガイドツアー開催日' => '#e3f2fd', '結婚式' => '#fff0fc', '夜間特別開館' => '#fff9f4', 'コンサート' => '#f5f5f5', 'イベント' => '#f5f5f5'], 'The colors must be stored trimmed and in lower case, and a cleared color as empty.');
    $pages = $kindPages();
    $check(substr_count($pages['month'], '<li data-title="休館日" data-kind="closed" style="--museum-calendar-kind-color: #e26b6b"><strong>休館日</strong>') === 4, 'A row with a changed color must set that color in the month.');
    $check(substr_count($pages['month'], '<li data-title="開館日" data-kind="open"><strong>') === 25 && substr_count($pages['month'], '<li data-title="ガイドツアー開催日" data-kind="tour" style="--museum-calendar-kind-color: #e3f2fd">') === 17, 'An event of a row without a color must have no style attribute.');
    $html = (new Renderer($facility, Settings::kinds()))->renderToday(new DateTimeImmutable('2026-11-14 12:00', $tokyo), 'n/j');
    $check($status($html) === '<p class="museum-calendar-today__status" data-kind="closed" style="--museum-calendar-kind-color: #e26b6b"><strong>休館日</strong></p>', 'The status must set the kind color of its row.');
    $html = (new Renderer($facility, Settings::kinds()))->renderToday($sunday, 'n/j');
    $check($status($html) === '<p class="museum-calendar-today__status" data-kind="open"><strong>開館日</strong><span>10:00–16:00</span></p>', 'A status from a row without a color must have no style attribute.');
    $check(str_contains($html, '<li data-title=" ガイドツアー開催日 " data-kind="tour" style="--museum-calendar-kind-color: #e3f2fd"><strong> ガイドツアー開催日 </strong>') && str_contains($html, '<li data-title="臨時休館" data-kind="other"><strong>'), 'Today must set the kind color only on the events of a row with a color.');
    Settings::saveKinds(['reset' => '1']);
    $check(get_option(Settings::KINDS_OPTION, null) === null && $kindPages() === $defaultPages, 'A reset must bring back the default colors.');
    wp_set_current_user($previousUser);

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

    // アンインストールは最後に実行する。finally のブロックが復元するオプションを削除するため。
    $options = array_values(array_filter(
        [...(new ReflectionClass(Settings::class))->getConstants(), ...(new ReflectionClass(FeedRepository::class))->getConstants()],
        static fn (mixed $value): bool => is_string($value) && str_starts_with($value, 'museum_calendar_'),
    ));
    foreach ($options as $option) {
        update_option($option, 'stored before uninstall', false);
    }
    if (!wp_next_scheduled('museum_calendar_refresh_feed')) {
        wp_schedule_event(time() + FeedRepository::INTERVAL, 'museum_calendar_quarter_hour', 'museum_calendar_refresh_feed');
    }
    $check(in_array(Settings::SECRET_OPTION, $options, true) && in_array(FeedRepository::LOCK, $options, true) && get_option(Settings::SECRET_OPTION) === 'stored before uninstall' && wp_next_scheduled('museum_calendar_refresh_feed') !== false, 'Uninstalling must start from a site with every option and the refresh hook.');
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $plugin = plugin_basename(dirname((new ReflectionClass(Settings::class))->getFileName(), 3) . '/museum-calendar.php');
    $check(is_uninstallable_plugin($plugin), 'WordPress must find the uninstall file of the plugin.');
    uninstall_plugin($plugin);
    $check(array_filter($options, static fn (string $option): bool => get_option($option, null) !== null) === [], 'Uninstalling must delete every option the plugin writes.');
    $left = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('museum_calendar_') . '%'));
    $check($left === [], 'Uninstalling left options: ' . implode(', ', $left));
    $check(wp_next_scheduled('museum_calendar_refresh_feed') === false, 'Uninstalling must clear the refresh hook.');
} finally {
    if ($previousLock === null) {
        delete_option(FeedRepository::LOCK);
    } else {
        update_option(FeedRepository::LOCK, $previousLock, false);
    }
    wp_clear_scheduled_hook('museum_calendar_refresh_feed');
    if ($previousCron !== false) {
        wp_schedule_event($previousCron, 'museum_calendar_quarter_hour', 'museum_calendar_refresh_feed');
    }
    wp_set_current_user($previousUser);
    if ($previousKinds === null) {
        delete_option(Settings::KINDS_OPTION);
    } else {
        update_option(Settings::KINDS_OPTION, $previousKinds, false);
    }
    if ($previousCalendar === null) {
        delete_option(\MuseumCalendar\Admin\Settings::OPTION);
    } else {
        update_option(\MuseumCalendar\Admin\Settings::OPTION, $previousCalendar, false);
    }
    if ($previousSecret === null) {
        delete_option(Settings::SECRET_OPTION);
    } else {
        update_option(Settings::SECRET_OPTION, $previousSecret, false);
    }
    remove_filter('pre_http_request', $sampleFeed, 10);
    if ($previousFeed === null) {
        delete_option(FeedRepository::CACHE);
    } else {
        update_option(FeedRepository::CACHE, $previousFeed, false);
    }
}
WP_CLI::success("Integration: {$checks} checks passed.");
