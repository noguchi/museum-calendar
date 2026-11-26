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
    $check(shortcode_exists('museum_calendar') && shortcode_exists('museum_calendar_today'), 'ショートコードが登録されている。');
    $check(wp_style_is('museum-calendar', 'registered'), 'テーマが使うスタイルシートのハンドルが登録されている。');
    update_option(Settings::OPTION, $sampleCalendar, false);
    $html = do_shortcode('[museum_calendar month="2024-02"]');
    $check(str_contains($html, '<caption>February 2024</caption>'), '指定した月を表示する。');
    $check(str_contains($html, '2024-02-29'), 'うるう日を表示する。');
    $check(!str_contains($html, '2024-02-30'), 'ありえない日を表示しない。');
    foreach (['休館日' => 4, '開館日' => 25, 'ガイドツアー開催日' => 17, 'お茶室営業日' => 8] as $title => $count) {
        $check(substr_count($html, "<strong>{$title}</strong>") === $count, "2024年2月は {$title} を {$count} 回表示する。");
    }
    $check(!str_contains($html, 'museum-calendar__nav'), '固定月には効かない月送りを出さない。');
    $check(!str_contains($html, 'aria-current'), '過去の月には本日の印を付けない。');
    $tokyo = new DateTimeZone('Asia/Tokyo');
    $now = new DateTimeImmutable('now', $tokyo);
    $today = $now->format('Y-m-d');
    $html = do_shortcode('[museum_calendar]');
    $check(preg_match('/<td data-weekday="' . $now->format('N') . '" aria-current="date">\s*<time datetime="' . $today . '">/', $html) === 1, '当月は本日に印を付ける。');
    $dateLine = static fn (string $date, string $label): string
        => '<p class="museum-calendar-today__date"><time datetime="' . $date . '">' . $label . '</time></p>';
    $weekdayNames = ['日', '月', '火', '水', '木', '金', '土'];
    $inJapanese = require dirname(__DIR__) . '/support/japanese-locale.php';
    $dateFormat = get_option('date_format');
    try {
        update_option('date_format', 'Y.m.d');
        $html = do_shortcode('[museum_calendar_today]');
        $check(str_contains($html, $dateLine($today, $now->format('Y.m.d'))), '本日のショートコードは、Asia/Tokyoの日付をWordPressの日付形式で表示する。');
        $check(!str_contains($html, 'museum-calendar-today__weekday'), '本日は、日付形式にない曜日を出さない。');
        update_option('date_format', 'n月j日(D)');
        $check(str_contains($inJapanese(static fn (): string => do_shortcode('[museum_calendar_today]')), $dateLine($today, $now->format('n月j日') . '(' . $weekdayNames[(int) $now->format('w')] . ')')), '曜日を含む日付形式では、サイトの言語の曜日を出す。');
        $check(str_contains(do_shortcode('[museum_calendar_today]'), $dateLine($today, wp_date('n月j日(D)', $now->getTimestamp(), $tokyo))), '曜日を含む日付形式が、テストのサイトの言語で正しく出る。');
        $fallback = $dateLine($today, $now->format('Y年n月j日'));
        update_option('date_format', '');
        $check(get_option('date_format') === '', '空の日付形式を保存できる。');
        $check(str_contains(do_shortcode('[museum_calendar_today]'), $fallback), '空の日付形式は Y年n月j日 になる。');
        foreach ([false, null, 0, []] as $invalid) {
            $stored = static fn () => $invalid;
            add_filter('option_date_format', $stored);
            try {
                $check(str_contains(do_shortcode('[museum_calendar_today]'), $fallback), '文字列でない日付形式は Y年n月j日 になる: ' . var_export($invalid, true));
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
    foreach (['ガイドツアー開催日', 'お茶室営業日'] as $title) {
        $check(!str_contains($html, $title), "サンプルの本日は開館状況以外の予定を出さない: {$title}。");
    }
    $sampleStatus = $samples === ['休館日']
        ? '<p class="museum-calendar-today__status" data-kind="closed" style="--museum-calendar-kind-color: #f5f5f5"><strong>休館日</strong></p>'
        : '<p class="museum-calendar-today__status" data-kind="open" style="--museum-calendar-kind-color: #ffebee"><strong>開館日</strong><span>10:30–16:30</span></p>';
    $check(str_contains($html, $sampleStatus) && !str_contains($html, '<ul') && !str_contains($html, '<li'), 'サンプルの本日は、その曜日の開館日か休館日を開館状況として表示する。');
    $check(str_contains(do_shortcode('[museum_calendar month="invalid"]'), '正しくありません'), '不正な入力を受け付けない。');

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
        $check($sunday['labels'] === 'Sun Mon Tue Wed Thu Fri Sat' && $sunday['numbers'] === '7123456', '週の始まりが0なら、最初の列は日曜日。');
        $check($sunday['rows'][0] === ['7 2026-11-01', '1 2026-11-02', '2 2026-11-03', '3 2026-11-04', '4 2026-11-05', '5 2026-11-06', '6 2026-11-07'], '日曜日に始まる月は、日曜始まりの最初の週を埋める。');
        $check(count($sunday['rows']) === 5 && $sunday['rows'][4] === ['7 2026-11-29', '1 2026-11-30', '-', '-', '-', '-', '-'], '2026年11月の日曜始まりの最後の週が正しい。');
        $html = do_shortcode('[museum_calendar month="2026-12"]');
        $sunday = $grid($html);
        $check($sunday['rows'][0] === ['-', '-', '2 2026-12-01', '3 2026-12-02', '4 2026-12-03', '5 2026-12-04', '6 2026-12-05'], '2026年12月1日（火）は日曜始まりの3列目に入る。');
        $check($sunday['rows'][1][0] === '7 2026-12-06' && $sunday['rows'][4] === ['7 2026-12-27', '1 2026-12-28', '2 2026-12-29', '3 2026-12-30', '4 2026-12-31', '-', '-'], '日曜始まりの日付は、その曜日の列に入る。');
        $check(str_contains($html, '<caption>December 2026</caption>') && !str_contains($html, 'museum-calendar__nav'), '週の始まりが日曜日でも、固定月は月送りなしで表示する。');
        $check(preg_match('/<td data-weekday="1"[^>]*>\s*<time datetime="2026-12-07">7<\/time>\s*<ul>\s*<li data-title="休館日" data-kind="closed" style="--museum-calendar-kind-color: #f5f5f5">/', $html) === 1, '週の始まりが日曜日でも、月曜日のサンプルは月曜日に出る。');
        $html = do_shortcode('[museum_calendar]');
        $check($grid($html)['labels'] === 'Sun Mon Tue Wed Thu Fri Sat', '当月は週の始まりに従う。');
        $check(preg_match('/<td data-weekday="' . $now->format('N') . '" aria-current="date">\s*<time datetime="' . $today . '">/', $html) === 1, '週の始まりが日曜日でも、本日の曜日は変わらない。');
        $check(preg_match_all('/<a href="[^"]*museum_month=\d{4}-\d{2}#museum-calendar"/', $html) === 2, '週の始まりが日曜日でも、月送りのリンクを出す。');
        $check(do_shortcode('[museum_calendar_today]') === $todayHtml, '週の始まりで本日のショートコードは変わらない。');

        update_option('start_of_week', 3);
        $wednesday = $grid(do_shortcode('[museum_calendar month="2026-12"]'));
        $check($wednesday['labels'] === 'Wed Thu Fri Sat Sun Mon Tue' && $wednesday['numbers'] === '3456712', '週の始まりが3なら、最初の列は水曜日。');
        $japanese = $grid($inJapanese(static fn (): string => do_shortcode('[museum_calendar month="2026-12"]')));
        $check($japanese['labels'] === '水 木 金 土 日 月 火' && $japanese['numbers'] === '3456712', '曜日の見出しはサイトの言語で、ISO 8601の番号を保つ。');
        $check($japanese['rows'] === $wednesday['rows'], 'サイトの言語で日付の位置は変わらない。');
        $check($wednesday['rows'][0] === ['-', '-', '-', '-', '-', '-', '2 2026-12-01'] && $wednesday['rows'][1][0] === '3 2026-12-02', '水曜始まりの日付は、その曜日の列に入る。');

        update_option('start_of_week', 1);
        $monday = $grid(do_shortcode('[museum_calendar month="2026-11"]'));
        $check($monday['labels'] === 'Mon Tue Wed Thu Fri Sat Sun' && $monday['numbers'] === '1234567', '週の始まりが1なら、最初の列は月曜日。');
        $check($monday['rows'][0] === ['-', '-', '-', '-', '-', '-', '7 2026-11-01'] && count($monday['rows']) === 6, '月曜始まりの2026年11月は最後の列から始まる。');

        update_option('start_of_week', 9);
        $check((int) get_option('start_of_week') === 9, '範囲外の週の始まりを保存できる。');
        $check($grid(do_shortcode('[museum_calendar month="2026-11"]')) === $monday, '範囲外の週の始まりでは月曜始まりで表示する。');
        update_option('start_of_week', 0);
        foreach (['abc', '1.5', '-1', false, []] as $invalid) {
            $stored = static fn () => $invalid;
            add_filter('option_start_of_week', $stored);
            try {
                $check($grid(do_shortcode('[museum_calendar month="2026-11"]')) === $monday, '0〜6でない週の始まりでは月曜始まりで表示する: ' . var_export($invalid, true));
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
    $check(!str_contains($html, '<script>'), '予定のタイトルをエスケープする。');
    $check(!str_contains($html, '<img'), '予定の時間をエスケープする。');
    $check(str_contains($html, '&lt;script&gt;'), 'エスケープした文字を失わない。');
    $check(str_contains($html, '<li data-title="&lt;script&gt;alert(1)&lt;/script&gt;" data-kind="other">'), '予定のタイトルの属性があり、エスケープされている。');
    $check(str_contains($html, '<section class="museum-calendar" id="museum-calendar"'), 'カレンダーのアンカーがある。');
    $check(substr_count($html, 'aria-current="date"') === 1 && preg_match($currentCell, $html) === 1, '本日のセルだけに印を付ける。');
    preg_match_all('/<a href="([^"]*)"/', $html, $links);
    $check(count($links[1]) === 2, '月送りがある。');
    $check(str_contains($links[1][0], 'museum_month=2026-10') && str_ends_with($links[1][0], '#museum-calendar'), '前月のリンクはカレンダーの位置に戻る。');
    $check(str_contains($links[1][1], 'museum_month=2026-12') && str_ends_with($links[1][1], '#museum-calendar'), '翌月のリンクはカレンダーの位置に戻る。');
    $html = (new Renderer($source, KindTable::defaults()))->render(new Month('2026-11'), new DateTimeImmutable('2026-12-01 00:00', $tokyo));
    $check(!str_contains($html, 'aria-current'), '本日を含まない月は、どのセルにも印を付けない。');
    $html = (new Renderer($source, KindTable::defaults()))->render(new Month('2026-11'), new DateTimeImmutable('2026-11-14 15:30', new DateTimeZone('UTC')));
    $check(preg_match($currentCell, $html) === 1, '本日はAsia/Tokyoで判定する。');

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
    $check($header($monthHtml('2026-11')) === $november, '月の見出しが、テストのサイトの言語で正しく出る。');
    $check($header($monthHtml('2026-11', false)) === ['nav' => null, 'links' => [], 'caption' => 'November 2026'], '月送りのない月は、見出しを保ちリンクを出さない。');
    $check($header($inJapanese(static fn (): string => $monthHtml('2026-11'))) === ['nav' => 'Previous and next months', 'links' => ['2026-10 &laquo; 10月', '2026-12 12月 &raquo;'], 'caption' => '11月 2026'], '見出しの月の名前はサイトの言語で出す。');
    foreach ([
        '2026-01' => [['2025-12 &laquo; December', '2026-02 February &raquo;'], 'January 2026', ['2025-12 &laquo; 12月', '2026-02 2月 &raquo;']],
        '2026-12' => [['2026-11 &laquo; November', '2027-01 January &raquo;'], 'December 2026', ['2026-11 &laquo; 11月', '2027-01 1月 &raquo;']],
        '1900-01' => [['1900-02 February &raquo;'], 'January 1900', ['1900-02 2月 &raquo;']],
        '2100-12' => [['2100-11 &laquo; November'], 'December 2100', ['2100-11 &laquo; 11月']],
    ] as $month => [$links, $caption, $japaneseLinks]) {
        $shown = $header($monthHtml($month));
        $check($shown['links'] === $links && $shown['caption'] === $caption, "月送りのリンクと見出しが正しい: {$month}。");
        $check($header($inJapanese(static fn (): string => $monthHtml($month)))['links'] === $japaneseLinks, "日本語の月送りのリンクが正しい: {$month}。");
    }
    add_filter('pre_option_timezone_string', $losAngeles = static fn (): string => 'America/Los_Angeles');
    try {
        $check(wp_timezone_string() === 'America/Los_Angeles', 'サイトのタイムゾーンを差し替えられる。');
        $check($header($monthHtml('2026-11')) === $november, '月の見出しは、サイトのタイムゾーンにかかわらずAsia/Tokyoで月を決める。');
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
        $check($header($inJapanese(static fn (): string => $monthHtml('2026-11'))) === ['nav' => '前と次の月', 'links' => ['2026-10 &laquo; 10月', '2026-12 12月 &raquo;'], 'caption' => '2026年11月'], '月の見出しは、月別の形式と月送りのラベルにWordPressの翻訳を使う。');
        $monthly = '<b>Y</b>';
        $check(str_contains($monthHtml('2026-11'), '<caption>&lt;b&gt;2026&lt;/b&gt;</caption>'), '月の見出しをエスケープする。');
    } finally {
        remove_filter('gettext_with_context', $monthlyFormat, 10);
        remove_filter('gettext', $navLabel, 10);
    }

    $html = (new Renderer($source, KindTable::defaults()))->renderToday(new DateTimeImmutable('2026-11-15 12:00', $tokyo), 'n/j');
    $check(str_contains($html, $dateLine('2026-11-15', '11/15')), '日曜日の本日の日付が正しい。');
    foreach (['Zebra', 'Alpha', 'script', 'img', 'Tomorrow', '<ul', '<li'] as $hidden) {
        $check(!str_contains($html, $hidden), "開館状況のない本日は、ほかの予定を出さない: {$hidden}");
    }
    $check(str_contains($html, '<p class="museum-calendar-today__empty">本日の開館情報はありません。</p>') && !str_contains($html, '__status') && !str_contains($html, 'role="status"'), 'ほかの予定だけの本日は、開館情報がないとだけ表示する。');
    $html = (new Renderer($source, KindTable::defaults()))->renderToday(new DateTimeImmutable('2026-11-14 12:00', $tokyo), 'Y年n月j日');
    $check(str_contains($html, $dateLine('2026-11-14', '2026年11月14日')), '土曜日の本日の日付が正しい。');
    $check(str_contains($html, '本日の開館情報はありません。') && !str_contains($html, '<ul') && !str_contains($html, '__status'), '予定のない日は、開館情報がないと表示し、開館状況を出さない。');
    $html = $inJapanese(static fn (): string => (new Renderer($source, KindTable::defaults()))->renderToday(new DateTimeImmutable('2026-11-16 12:00', $tokyo), 'n月j日(D)'));
    $check(str_contains($html, $dateLine('2026-11-16', '11月16日(月)')), '月曜日の本日が正しい。');
    $html = (new Renderer($source, KindTable::defaults()))->renderToday(new DateTimeImmutable('2027-01-05 12:00', $tokyo), 'Y/m/d n/j');
    $check(str_contains($html, $dateLine('2027-01-05', '2027/01/05 1/5')), '本日の日付は、日付形式が指定するところだけ0で埋める。');
    $html = $inJapanese(static fn (): string => (new Renderer($source, KindTable::defaults()))->renderToday(new DateTimeImmutable('2026-11-14 15:30', new DateTimeZone('UTC')), 'n月j日(D) H:i'));
    $check(str_contains($html, $dateLine('2026-11-15', '11月15日(日) 00:30')), '本日はAsia/Tokyoで判定する。');
    $html = (new Renderer($source, KindTable::defaults()))->renderToday(new DateTimeImmutable('2026-11-15 12:00', $tokyo), '<b>n/j</b>');
    $check(str_contains($html, $dateLine('2026-11-15', '&lt;b&gt;11/15&lt;/b&gt;')), '本日の日付をエスケープする。');
    $html = (new Renderer($source, KindTable::defaults(), '<b>前回の予定</b>'))->renderToday(new DateTimeImmutable('2026-11-15 12:00', $tokyo), 'n/j');
    $check(str_contains($html, '<p class="museum-calendar-today__notice" role="status">&lt;b&gt;前回の予定&lt;/b&gt;</p>'), '本日に前回データの案内があり、エスケープされている。');

    $japaneseText = ['月間カレンダー', '本日の予定', '月間予定表', '予定はありません', '終日', '正しくありません', '表示できません', '設定されていません', '取得'];
    $allEnglish = static function (string $html, string $where) use ($check, $japaneseText): void {
        foreach ($japaneseText as $text) {
            $check(!str_contains($html, $text), "{$where} は lang=\"en\" で日本語の {$text} を出さない。");
        }
    };
    $monthSection = '<section class="museum-calendar" id="museum-calendar" aria-label="%s"%s>' . "\n";
    $todaySection = '<section class="museum-calendar-today" aria-label="%s"%s>' . "\n";
    $html = do_shortcode('[museum_calendar month="2024-02" lang="en"]');
    $check(str_starts_with($html, sprintf($monthSection, 'Monthly calendar', ' lang="en"')), '英語の月はsectionに言語を宣言する。');
    $check(str_contains($html, '<div class="museum-calendar__scroll" tabindex="0" role="region" aria-label="Monthly schedule (scrolls sideways)">'), '英語の月は横スクロールの領域に英語のラベルを付ける。');
    $check(str_contains($html, '<caption>February 2024</caption>') && substr_count($html, '<time datetime=') === 29, '英語の月は指定した月を表示する。');
    $check(substr_count($html, '<li data-title="休館日" data-kind="closed" style="--museum-calendar-kind-color: #f5f5f5"><strong>CLOSED</strong><span>All day</span></li>') === 4, '英語の月は、サンプルのタイトルの英語での表示を英語の終日の時間と並べて出す。');
    $check(substr_count($html, '<li data-title="開館日" data-kind="open" style="--museum-calendar-kind-color: #ffebee"><strong>OPEN</strong><span>10:30–16:30</span></li>') === 25, '英語の月は、時刻のある時間をそのまま出す。');
    $allEnglish($html, '月間カレンダー');
    $html = do_shortcode('[museum_calendar month="2024-02"]');
    $check(str_starts_with($html, sprintf($monthSection, '月間カレンダー', '')) && !str_contains($html, ' lang='), 'langのない月は日本語の文言を保ち、言語を宣言しない。');
    $check(str_contains($html, '<div class="museum-calendar__scroll" tabindex="0" role="region" aria-label="月間予定表（横にスクロールできます）">'), 'langのない月は、横スクロールの領域の日本語のラベルを保つ。');
    $check(substr_count($html, '<li data-title="休館日" data-kind="closed" style="--museum-calendar-kind-color: #f5f5f5"><strong>休館日</strong><span>終日</span></li>') === 4, 'langのない月は、予定のマークアップを保つ。');
    $check(do_shortcode('[museum_calendar month="2024-02" lang=""]') === $html, '空のlangはlangなしと同じに表示する。');

    $dateFormat = get_option('date_format');
    $startOfWeek = get_option('start_of_week');
    try {
        update_option('date_format', 'n月j日(D)');
        update_option('start_of_week', 1);
        $html = do_shortcode('[museum_calendar_today lang="en"]');
        $check(str_starts_with($html, sprintf($todaySection, 'Today&#039;s schedule', ' lang="en"')), '英語の本日はsectionに言語を宣言する。');
        $check(str_contains($html, $dateLine($today, $now->format('D, M j'))), '英語の本日は、日付形式にかかわらず日付を D, M j で出す。');
        $check(!str_contains($html, '<li') && !str_contains($html, 'Guided Tour') && !str_contains($html, 'Tea Room Open'), '英語の本日は、サンプルの開館状況以外の予定を出さない。');
        $check(str_contains($html, str_replace(['<strong>休館日</strong>', '<strong>開館日</strong>'], ['<strong>CLOSED</strong>', '<strong>OPEN</strong>'], $sampleStatus)), '英語の本日は、サンプルの開館状況を英語で出す。');
        $allEnglish($html, '本日');
        $html = do_shortcode('[museum_calendar_today]');
        $check(str_starts_with($html, sprintf($todaySection, '本日の予定', '')) && !str_contains($html, ' lang='), 'langのない本日は日本語の文言を保ち、言語を宣言しない。');
        $check(str_contains($html, $dateLine($today, wp_date('n月j日(D)', $now->getTimestamp(), $tokyo))), 'langのない本日はWordPressの日付形式を保つ。');
        $check(do_shortcode('[museum_calendar_today lang=""]') === $html, '空のlangでは本日をlangなしと同じに表示する。');

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
        $check($header($seen['before']) === $japaneseHeader && $grid($seen['before'])['labels'] === '月 火 水 木 金 土 日' && str_contains($seen['before'], $japaneseDate), 'テストの日本語のサイトは日本語の名前を出す。');
        $check($header($seen['month']) === $englishHeader, '日本語のサイトの英語の月は、見出し、リンク、月送りのラベルを英語で出す。');
        $check($grid($seen['month'])['labels'] === 'Mon Tue Wed Thu Fri Sat Sun', '日本語のサイトの英語の月は、英語の曜日名を出す。');
        $check(str_contains($seen['today'], $dateLine($today, $now->format('D, M j'))), '日本語のサイトの英語の本日は、英語の曜日名と月名を出す。');
        $allEnglish($seen['month'], '日本語のサイトの月間カレンダー');
        $allEnglish($seen['today'], '日本語のサイトの本日');
        $check($seen['locale after month'] === 'ja' && $seen['locale after today'] === 'ja', '英語のショートコードはサイトのロケールに戻す。');
        $check($seen['after'] === $seen['before'], 'langのないショートコードは、英語のショートコードの後も同じに出る。');
        $check(determine_locale() === 'en_US' && !is_locale_switched(), 'テストのサイトは元のロケールに戻っている。');
        $check($header(do_shortcode('[museum_calendar lang="en"]')) === $englishHeader && !is_locale_switched(), '英語のサイトの英語の月は、ロケールの切り替えを残さずに表示する。');

        $sample = (new DateTimeImmutable('2026-11-15 12:00', $tokyo))->getTimestamp();
        $names = static fn (): string => wp_date('D, F', $sample, $tokyo);
        $inJapanese(static function () use ($check, $names): string {
            $check($names() === '日, 11月', 'テストの日本語のサイトは、曜日と月を日本語で出す。');
            $check(Language::English->withLocale($names) === 'Sun, November', '英語は英語のロケールで描画する。');
            $check(Language::Japanese->withLocale($names) === '日, 11月', '日本語はサイトのロケールで描画する。');
            $failure = null;
            try {
                Language::English->withLocale(static function (): string {
                    throw new RuntimeException('The rendering failed.');
                });
            } catch (RuntimeException $error) {
                $failure = $error->getMessage();
            }
            $check($failure === 'The rendering failed.', '描画の失敗は呼び出し元に届く。');
            $check(determine_locale() === 'ja' && $names() === '日, 11月', '英語の描画に失敗しても、サイトのロケールに戻す。');
            $check(switch_to_locale('en_US'), 'テストが en_US に切り替えられる。');
            try {
                $html = do_shortcode('[museum_calendar_today lang="en"]');
                $check(str_contains($html, 'lang="en"') && determine_locale() === 'en_US', '英語のショートコードは、ほかのコードのロケールの切り替えを残す。');
            } finally {
                restore_previous_locale();
            }
            $check(determine_locale() === 'ja', 'テストが自分のロケールの切り替えを戻せる。');
            return '';
        });
    } finally {
        update_option('date_format', $dateFormat);
        update_option('start_of_week', $startOfWeek);
    }

    $invalidLanguage = '<p class="museum-calendar-notice">言語の指定が正しくありません。</p>';
    foreach (['ja', 'EN', 'en_US', 'en-US', 'english', 'fr'] as $invalid) {
        foreach (['museum_calendar', 'museum_calendar month="2026-11"', 'museum_calendar month="invalid"', 'museum_calendar_today'] as $shortcode) {
            $check(do_shortcode("[{$shortcode} lang=\"{$invalid}\"]") === $invalidLanguage, "[{$shortcode}] は lang=\"{$invalid}\" を受け付けない。");
        }
    }
    $check(do_shortcode('[museum_calendar month="invalid" lang="en"]') === '<p class="museum-calendar-notice" lang="en">The month is not valid.</p>', '不正な月は英語で案内する。');
    $check(do_shortcode('[museum_calendar month="invalid"]') === '<p class="museum-calendar-notice">年月の指定が正しくありません。</p>', '不正な月の案内が変わっていない。');

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
        $check($monthLinks(do_shortcode('[museum_calendar]')) === ['previous' => $fromNow(-1), 'next' => $fromNow(1)], '当月は前月と翌月にリンクする。');
        $html = $byQuery($fromNow(1));
        $check(str_contains($html, $caption($fromNow(1))) && $monthLinks($html) === ['previous' => $fromNow(0), 'next' => $fromNow(2)], '翌月は翌々月にリンクする。');
        $html = $byQuery($fromNow(2));
        $check(str_contains($html, $caption($fromNow(2))) && $monthLinks($html) === ['previous' => $fromNow(1), 'next' => null], '翌々月は前月のリンクだけを出して表示する。');
        $check(str_contains($byQuery($fromNow(2), ' lang="en"'), $caption($fromNow(2))), '英語の翌々月を表示する。');
        $future = '<p class="museum-calendar-notice">この月の予定はまだ表示できません。</p>';
        $check($byQuery($fromNow(3)) === $future, '翌々月より後の月は案内だけを出す。');
        $check($byQuery($fromNow(14)) === $future, '1年先の月は案内だけを出す。');
        $check($byQuery($fromNow(3), ' lang="en"') === '<p class="museum-calendar-notice" lang="en">This month cannot be shown yet.</p>', '翌々月より後の月の案内は lang="en" の英語。');
        $check($byQuery('2026-13') === '<p class="museum-calendar-notice">年月の指定が正しくありません。</p>', '不正なクエリの月では、不正な月の案内を保つ。');
        $html = $byQuery('1950-06');
        $check(str_contains($html, $caption('1950-06')) && $monthLinks($html) === ['previous' => '1950-05', 'next' => '1950-07'], 'ずっと過去の月は両方のリンクを出して表示する。');
        $html = $byQuery($fromNow(3), ' month="' . $fromNow(6) . '"');
        $check(str_contains($html, $caption($fromNow(6))) && !str_contains($html, 'museum-calendar__nav') && !str_contains($html, 'まだ表示できません'), '固定月は、本日からの距離にかかわらず月送りなしで表示する。');
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
        $check(str_contains($html, '<p class="museum-calendar__empty">No events this month.</p>') && !str_contains($html, '<li'), '予定のない英語の月は、予定がないと英語で出す。');
        $allEnglish($html, '予定のない月');
        $html = do_shortcode('[museum_calendar_today lang="en"]');
        $check(str_contains($html, '<p class="museum-calendar-today__empty">No opening information today.</p>') && !str_contains($html, '<ul'), '予定のない英語の日は、開館情報がないと英語で出す。');
        $allEnglish($html, '予定のない日');
        $check(str_contains(do_shortcode('[museum_calendar]'), '<p class="museum-calendar__empty">この月の予定はありません。</p>'), '予定のない月の文言が変わっていない。');
        $check(str_contains(do_shortcode('[museum_calendar_today]'), '<p class="museum-calendar-today__empty">本日の開館情報はありません。</p>'), '予定のない日の文言が変わっていない。');
    } finally {
        $sampleIcs = $sampleEvents;
        FeedRepository::clear();
    }
    delete_option(Settings::OPTION);
    try {
        foreach (['museum_calendar', 'museum_calendar_today'] as $shortcode) {
            $check(do_shortcode("[{$shortcode} lang=\"en\"]") === '<p class="museum-calendar-notice" lang="en">The calendar connection is not set up.</p>', "[{$shortcode}] は接続がないことを英語で案内する。");
            $check(do_shortcode("[{$shortcode}]") === '<p class="museum-calendar-notice">カレンダーの接続が設定されていません。</p>', "接続のない [{$shortcode}] の案内が変わっていない。");
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
        $check(substr_count($html, '<li') === count($items), "{$where} は予定を正しい数だけ出す。");
        $offset = 0;
        foreach ($items as $item) {
            $position = strpos($html, $item, $offset);
            $check($position !== false, "{$where} は次を順に出す: {$item}");
            $offset = $position + strlen($item);
        }
    };
    $html = (new Renderer($facility, KindTable::defaults(), '', Language::English))->render(new Month('2026-11'), $sunday);
    $listed($html, [$closed, ...$englishItems], '英語の月');
    $check(!str_contains($html, '<b>'), '英語での表示のないタイトルをエスケープする。');
    $html = (new Renderer($facility, KindTable::defaults(), '', Language::English))->renderToday($sunday, 'D, M j');
    $check(!str_contains($html, '<ul') && !str_contains($html, '<li') && !str_contains($html, 'Guided Tour') && !str_contains($html, 'Tea Room Open') && !str_contains($html, '__empty'), '英語の本日は、開館状況のほかに予定を出さない。');
    $check(str_contains($html, $dateLine('2026-11-15', 'Sun, Nov 15') . "\n" . '<p class="museum-calendar-today__status" data-kind="open" style="--museum-calendar-kind-color: #ffebee"><strong>OPEN</strong><span>10:00–16:00</span></p>'), '英語の本日は、日付の後に開館日の開館状況を時間とともに出す。');
    $html = (new Renderer($facility, KindTable::defaults()))->render(new Month('2026-11'), $sunday);
    $listed($html, $japaneseItems, 'langのない月');
    $check(!str_contains($html, ' lang='), 'langのない月は、どの予定にも日本語の印を付けない。');
    $html = (new Renderer($facility, KindTable::defaults()))->renderToday($sunday, 'n/j');
    $check(!str_contains($html, '<ul') && !str_contains($html, '<li') && !str_contains($html, 'ガイドツアー開催日') && !str_contains($html, 'お茶室営業日'), 'langのない本日は、開館状況のほかに予定を出さない。');
    $check(str_contains($html, '<p class="museum-calendar-today__status" data-kind="open" style="--museum-calendar-kind-color: #ffebee"><strong>開館日</strong><span>10:00–16:00</span></p>'), 'langのない本日は、開館日の開館状況を出す。');
    $check(!str_contains($html, ' lang='), 'langのない本日は、開館状況に日本語の印を付けない。');
    $status = static function (string $html): ?string {
        return preg_match('/<p class="museum-calendar-today__status"[^>]*>.*?<\/p>/', $html, $match) === 1 ? $match[0] : null;
    };
    $closedStatus = '<p class="museum-calendar-today__status" data-kind="closed" style="--museum-calendar-kind-color: #f5f5f5"><strong>%s</strong></p>';
    $html = (new Renderer($facility, KindTable::defaults(), '', Language::English))->renderToday(new DateTimeImmutable('2026-11-14 12:00', $tokyo), 'D, M j');
    $check($status($html) === sprintf($closedStatus, 'CLOSED'), '休館日だけの英語の本日は CLOSED を出す。');
    $check(!str_contains($html, '<ul') && !str_contains($html, '__empty') && !str_contains($html, 'No opening information'), '休館日だけの本日は開館状況だけを出す。');
    $check($status((new Renderer($facility, KindTable::defaults()))->renderToday(new DateTimeImmutable('2026-11-14 12:00', $tokyo), 'n/j')) === sprintf($closedStatus, '休館日'), 'langのない本日は、登録したタイトル休館日を出す。');
    $html = (new Renderer($facility, KindTable::defaults()))->renderToday(new DateTimeImmutable('2026-11-16 12:00', $tokyo), 'n/j');
    $check($status($html) === null && str_contains($html, '本日の開館情報はありません。'), '開館日も休館日もない日は、開館状況を出さず、休館とも読めない。');
    $openStatus = '<p class="museum-calendar-today__status" data-kind="open" style="--museum-calendar-kind-color: #ffebee"><strong>開館日</strong><span>10:00–16:00</span></p>';
    foreach ([
        ['2026-11-14 23:59:59', $tokyo, sprintf($closedStatus, '休館日')],
        ['2026-11-15 00:00:00', $tokyo, $openStatus],
        ['2026-11-15 23:59:59', $tokyo, $openStatus],
        ['2026-11-16 00:00:00', $tokyo, null],
        ['2026-11-14 14:59:59', new DateTimeZone('UTC'), sprintf($closedStatus, '休館日')],
        ['2026-11-14 15:00:00', new DateTimeZone('UTC'), $openStatus],
    ] as [$time, $zone, $expected]) {
        $check($status((new Renderer($facility, KindTable::defaults()))->renderToday(new DateTimeImmutable($time, $zone), 'n/j')) === $expected, "時刻 {$time} {$zone->getName()} の開館状況はAsia/Tokyoのその日のもの。");
    }
    $html = (new Renderer($facility, KindTable::defaults(), 'Shown <b>earlier</b>', Language::English))->renderToday($sunday, 'D, M j');
    $check(str_starts_with($html, sprintf($todaySection, 'Today&#039;s schedule', ' lang="en"') . '<p class="museum-calendar-today__notice" role="status">Shown &lt;b&gt;earlier&lt;/b&gt;</p>' . "\n"), '英語の本日は案内をsectionの中に出す。');

    // 予定の種類の表。ここまではオプションがなく、ここより上の出力は表を保存していないサイトのもの。
    $check(get_option(Settings::KINDS_OPTION, null) === null && Settings::kinds()->rows() === KindTable::DEFAULTS, 'オプションのないサイトは既定の表を使う。');
    $kindPages = static fn (): array => [
        'month' => do_shortcode('[museum_calendar month="2024-02"]'),
        'english month' => do_shortcode('[museum_calendar month="2024-02" lang="en"]'),
        'today' => do_shortcode('[museum_calendar_today]'),
        'english today' => do_shortcode('[museum_calendar_today lang="en"]'),
    ];
    $defaultPages = $kindPages();
    $check(substr_count($defaultPages['english month'], '<li data-title="ガイドツアー開催日" data-kind="tour" style="--museum-calendar-kind-color: #e3f2fd"><strong>Guided Tour</strong><span>11:00–12:00</span></li>') === 17, '既定の表は、サンプルのツアーに種類、色、英語での表示を付ける。');
    $check(substr_count($defaultPages['month'], '<li data-title="開館日" data-kind="open" style="--museum-calendar-kind-color: #ffebee">') === 25 && substr_count($defaultPages['month'], '<li data-title="休館日" data-kind="closed" style="--museum-calendar-kind-color: #f5f5f5">') === 4, '既定の表は、サンプルの予定に色を付ける。');
    update_option(Settings::KINDS_OPTION, KindTable::DEFAULTS, false);
    $check($kindPages() === $defaultPages, '保存した既定の表は、表がないときと同じに出る。');
    foreach (['corrupt', 42, null, ['title' => '開館日'], [['title' => '開館日', 'kind' => 'other', 'english' => '', 'color' => '']], [['title' => '開館日', 'kind' => 'open', 'english' => '', 'color' => 'red']], [['title' => '開館日', 'kind' => 'open', 'english' => '', 'color' => null]], [['title' => '開館日', 'kind' => 'open', 'english' => '']], [['title' => '開館日', 'kind' => 'open']], [['title' => '開館日', 'kind' => 'open', 'english' => '', 'color' => ''], ['title' => '開館日', 'kind' => 'closed', 'english' => '', 'color' => '']]] as $corrupt) {
        update_option(Settings::KINDS_OPTION, $corrupt, false);
        $check(Settings::kinds()->rows() === KindTable::DEFAULTS && $kindPages() === $defaultPages, '壊れた種類の表は既定に戻る: ' . var_export($corrupt, true));
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
    $check(is_wp_error(Settings::saveKinds(['kinds' => $custom])) && get_option(Settings::KINDS_OPTION, null) === null, '訪問者は種類の表を保存できない。');
    require_once ABSPATH . 'wp-admin/includes/user.php';
    $editor = get_user_by('login', 'museum-kinds-editor');
    $editorId = $editor ? $editor->ID : wp_insert_user(['user_login' => 'museum-kinds-editor', 'user_pass' => wp_generate_password(), 'role' => 'editor']);
    try {
        wp_set_current_user($editorId);
        $check(!current_user_can('manage_options') && is_wp_error(Settings::saveKinds(['kinds' => $custom])) && get_option(Settings::KINDS_OPTION, null) === null, 'manage_options のないユーザーは種類の表を保存できない。');
    } finally {
        wp_delete_user($editorId);
    }
    wp_set_current_user(1);
    $calendar = get_option(Settings::OPTION, null);
    $blank = ['title' => '', 'kind' => '', 'english' => '', 'color' => ''];
    $form = [['title' => ' 開館日 ', 'kind' => 'open', 'english' => ' OPEN ', 'color' => ' '], ...array_slice($custom, 1)];
    $check(Settings::saveKinds(['kinds' => [5 => $form[0], 7 => $form[1], 9 => $blank, 10 => $form[2], 11 => $form[3], 12 => ['title' => '　', 'kind' => 'x!', 'english' => '', 'color' => 'red'], 13 => $form[4], 14 => $blank]]) === true, '正しい種類の表を受け付ける。');
    $check(get_option(Settings::KINDS_OPTION) === $custom, '種類の表は、前後の空白を除き、フォームの順に、タイトルのない行を除いて保存する。');
    global $wpdb;
    $check(in_array($wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Settings::KINDS_OPTION)), ['no', 'off'], true), '種類の表はautoloadしない。');
    $check(get_option(Settings::OPTION, null) === $calendar, '種類の表を保存してもカレンダーは変わらない。');

    $pages = $kindPages();
    $check(substr_count($pages['english month'], '<li data-title="開館日" data-kind="open"><strong>OPEN</strong><span>10:30–16:30</span></li>') === 8, 'closed として保存したタイトルは、その日の開館日を隠す。');
    $check(substr_count($pages['english month'], '<li data-title="ガイドツアー開催日" data-kind="closed"><strong>Closed for a Tour</strong><span>11:00–12:00</span></li>') === 17, '保存した行は、そのタイトルに種類と英語での表示を付ける。');
    $check(substr_count($pages['english month'], '<li data-title="お茶室営業日" data-kind="tea-room"><strong>Tea House</strong><span>12:00–16:00</span></li>') === 8, '新しい種類が data-kind に届く。');
    $check(substr_count($pages['english month'], '<li data-title="休館日" data-kind="closed"><strong lang="ja">休館日</strong><span>All day</span></li>') === 4, '英語のない行は種類を保ち、タイトルを日本語として出す。');
    $check(substr_count($pages['month'], 'data-kind="closed"><strong>ガイドツアー開催日</strong>') === 17 && substr_count($pages['month'], '<li data-title="開館日"') === 8, 'langのない月は保存した表に従う。');
    $todayStatus = static fn (string $kind, string $label, string $hours = ''): string
        => '<p class="museum-calendar-today__status" data-kind="' . $kind . '"><strong>' . $label . '</strong>' . ($hours === '' ? '' : '<span>' . $hours . '</span>') . '</p>';
    if ($samples === ['休館日']) {
        $check(str_contains($pages['english today'], '<p class="museum-calendar-today__status" data-kind="closed"><strong lang="ja">休館日</strong></p>'), '英語の本日は、英語のない closed のタイトルをそのまま日本語の印を付けて出す。');
    } elseif (in_array('お茶室営業日', $samples, true)) {
        $check(str_contains($pages['english today'], $todayStatus('open', 'OPEN', '10:30–16:30')) && !str_contains($pages['english today'], 'Tea House'), '英語の本日は、保存した英語での表示で開館状況を出し、ほかの予定を出さない。');
    } else {
        $check(str_contains($pages['english today'], $todayStatus('closed', 'Closed for a Tour')) && str_contains($pages['today'], $todayStatus('closed', 'ガイドツアー開催日')), 'closed として保存したタイトルは本日の開館状況になる。');
        $check(!str_contains($pages['english today'], '<ul') && !str_contains($pages['english today'], '10:30–16:30'), 'closed として保存したタイトルは本日の開館日を隠す。');
    }
    $html = (new Renderer($facility, Settings::kinds(), '', Language::English))->renderToday($sunday, 'D, M j');
    // 15日の closed の予定のうちガイドツアーが最初に来るので、それが開館状況になる。
    $check($status($html) === $todayStatus('closed', 'Closed for a Tour') && !str_contains($html, 'data-title="開館日"') && !str_contains($html, 'data-title="臨時休館"'), '2つ目の closed のタイトルは開館日に勝つ。');
    $html = (new Renderer($facility, Settings::kinds(), '', Language::English))->render(new Month('2026-11'), $sunday);
    $check(str_contains($html, '<li data-title="臨時休館" data-kind="closed"><strong>Closed Today</strong>') && !str_contains($html, 'data-title="開館日"'), '保存した closed のタイトルは、月間カレンダーでその日の開館日を隠す。');
    $check(str_contains($html, '<li data-title="結婚式" data-kind="other"><strong lang="ja">結婚式</strong>'), '保存した表から消した既定の行は other になる。');
    $html = (new Renderer($facility, Settings::kinds(), '', Language::English))->renderToday(new DateTimeImmutable('2026-11-14 12:00', $tokyo), 'D, M j');
    $check($status($html) === '<p class="museum-calendar-today__status" data-kind="closed"><strong lang="ja">休館日</strong></p>', '英語のない行の英語の開館状況は、ラベルに日本語の印を付ける。');
    $check($status((new Renderer($facility, Settings::kinds()))->renderToday(new DateTimeImmutable('2026-11-14 12:00', $tokyo), 'n/j')) === '<p class="museum-calendar-today__status" data-kind="closed"><strong>休館日</strong></p>', 'langのない開館状況はラベルに印を付けない。');

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
        $check(is_wp_error($result) && $result->get_error_code() === $code && $result->get_error_message() !== '', "不正な種類の表は次で拒否する: {$code}。");
        $check(get_option(Settings::KINDS_OPTION) === $custom, "拒否した種類の表（{$code}）は保存済みの表を変えない。");
    }
    $check(is_wp_error(Settings::saveKinds(['kinds' => [['title' => '開館日', 'kind' => 'open']]])) && get_option(Settings::KINDS_OPTION) === $custom, '英語のない行は拒否する。');
    foreach (['#fff', '#12345g', '#1234567', '2f6f4f', 'rgb(0, 0, 0)', '#2f6f4f;color:red'] as $color) {
        $result = Settings::saveKinds(['kinds' => [...$custom, ['title' => '講座', 'kind' => 'event', 'english' => '', 'color' => $color]]]);
        $check(is_wp_error($result) && $result->get_error_code() === 'invalid_color' && get_option(Settings::KINDS_OPTION) === $custom, "色 {$color} は拒否し、保存済みの表を変えない。");
    }
    $result = Settings::saveKinds(['kinds' => [...$custom, ['title' => '講座', 'kind' => 'event', 'english' => '', 'color' => ['#2f6f4f']]]]);
    $check(is_wp_error($result) && $result->get_error_code() === 'invalid_rows' && get_option(Settings::KINDS_OPTION) === $custom, '文字列でない色は invalid_rows で拒否する。');

    $check(Settings::saveKinds(['kinds' => [$blank, ['title' => ' 　', 'kind' => 'open', 'english' => 'OPEN', 'color' => '']]]) === true && get_option(Settings::KINDS_OPTION, null) === [], '行のない表は空の表として保存する。');
    $pages = $kindPages();
    $check(!str_contains($pages['month'], 'data-kind="open"') && !str_contains($pages['month'], 'data-kind="closed"') && substr_count($pages['month'], '<li data-title="開館日" data-kind="other">') === 25, '空の表では、どのタイトルも other になる。');
    $check(substr_count($pages['english month'], '<strong lang="ja">休館日</strong>') === 4 && !str_contains($pages['english today'], 'museum-calendar-today__status'), '空の表では、どのタイトルも日本語のままで、開館状況を出さない。');

    $check(Settings::saveKinds(['kinds' => [...array_map(static fn (array $row): array => ['title' => ' ' . $row['title'], 'english' => $row['english'] . '　'] + $row, KindTable::DEFAULTS), $blank, $blank]]) === true, '既定の表を受け付ける。');
    $check(get_option(Settings::KINDS_OPTION, null) === null, '既定の表を保存するとオプションを削除する。');
    Settings::saveKinds(['kinds' => $custom]);
    $check(Settings::saveKinds(['reset' => '1', 'kinds' => [['title' => '開館日', 'kind' => 'other', 'english' => '']]]) === true && get_option(Settings::KINDS_OPTION, null) === null, 'リセットはオプションを削除し、行を無視する。');
    $check($kindPages() === $defaultPages && get_option(Settings::OPTION, null) === $calendar, 'リセットの後は既定の表と同じに出て、カレンダーを保つ。');

    // 色。大文字と前後の空白は受け付け、小文字で保存する。色を空にすると、既定の行の色がなくなる。
    $colored = array_map(static fn (array $row): array => match ($row['title']) {
        '休館日' => array_replace($row, ['color' => ' #E26B6B ']),
        '開館日' => array_replace($row, ['color' => '']),
        default => $row,
    }, KindTable::DEFAULTS);
    $check(Settings::saveKinds(['kinds' => [...$colored, $blank]]) === true, '色のある種類の表を受け付ける。');
    $stored = get_option(Settings::KINDS_OPTION, null);
    $check(is_array($stored) && array_column($stored, 'color', 'title') === ['開館日' => '', '休館日' => '#e26b6b', 'お茶室営業日' => '#e8f5e9', 'ガイドツアー開催日' => '#e3f2fd', '結婚式' => '#fff0fc', '夜間特別開館' => '#fff9f4', 'コンサート' => '#f5f5f5', 'イベント' => '#f5f5f5'], '色は前後の空白を除いた小文字で保存し、消した色は空で保存する。');
    $pages = $kindPages();
    $check(substr_count($pages['month'], '<li data-title="休館日" data-kind="closed" style="--museum-calendar-kind-color: #e26b6b"><strong>休館日</strong>') === 4, '色を変えた行は、月間カレンダーでその色を付ける。');
    $check(substr_count($pages['month'], '<li data-title="開館日" data-kind="open"><strong>') === 25 && substr_count($pages['month'], '<li data-title="ガイドツアー開催日" data-kind="tour" style="--museum-calendar-kind-color: #e3f2fd">') === 17, '色のない行の予定にはstyle属性がない。');
    $html = (new Renderer($facility, Settings::kinds()))->renderToday(new DateTimeImmutable('2026-11-14 12:00', $tokyo), 'n/j');
    $check($status($html) === '<p class="museum-calendar-today__status" data-kind="closed" style="--museum-calendar-kind-color: #e26b6b"><strong>休館日</strong></p>', '開館状況はその行の種類の色を付ける。');
    $html = (new Renderer($facility, Settings::kinds()))->renderToday($sunday, 'n/j');
    $check($status($html) === '<p class="museum-calendar-today__status" data-kind="open"><strong>開館日</strong><span>10:00–16:00</span></p>', '色のない行の開館状況にはstyle属性がない。');
    Settings::saveKinds(['reset' => '1']);
    $check(get_option(Settings::KINDS_OPTION, null) === null && $kindPages() === $defaultPages, 'リセットで既定の色に戻る。');
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
    $check($problems === [], '既定のスタイルシートなしの描画で例外が出ない: ' . implode(' / ', $problems));
    $check(str_contains($html, '<table') && str_contains($html, 'museum-calendar-today__date'), 'テーマが既定のスタイルシートの登録を解除しても、ショートコードを表示する。');

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
    $check(in_array(Settings::SECRET_OPTION, $options, true) && in_array(FeedRepository::LOCK, $options, true) && get_option(Settings::SECRET_OPTION) === 'stored before uninstall' && wp_next_scheduled('museum_calendar_refresh_feed') !== false, 'アンインストールは、すべてのオプションと定期取得のフックがあるサイトから始める。');
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $plugin = plugin_basename(dirname((new ReflectionClass(Settings::class))->getFileName(), 3) . '/museum-calendar.php');
    $check(is_uninstallable_plugin($plugin), 'WordPressがプラグインのアンインストールのファイルを見つける。');
    uninstall_plugin($plugin);
    $check(array_filter($options, static fn (string $option): bool => get_option($option, null) !== null) === [], 'アンインストールはプラグインが書くオプションをすべて削除する。');
    $left = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('museum_calendar_') . '%'));
    $check($left === [], 'アンインストールでオプションを残さない: ' . implode(', ', $left));
    $check(wp_next_scheduled('museum_calendar_refresh_feed') === false, 'アンインストールは定期取得のフックを消す。');
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
WP_CLI::success("統合テスト: {$checks} 件の検査に合格しました。");
