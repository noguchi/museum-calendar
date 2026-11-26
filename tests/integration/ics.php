<?php
use MuseumCalendar\Admin\Settings;
use MuseumCalendar\Calendar\KindTable;
use MuseumCalendar\Calendar\Month;
use MuseumCalendar\Frontend\Language;
use MuseumCalendar\Frontend\Renderer;
use MuseumCalendar\Ics\FeedRepository;
use MuseumCalendar\Ics\Parser;
use MuseumCalendar\Ics\Source;

if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'development') {
    exit(1);
}
$saved = [];
foreach ([Settings::OPTION, Settings::SECRET_OPTION, Settings::KINDS_OPTION, FeedRepository::CACHE, FeedRepository::LOCK] as $key) {
    $saved[$key] = get_option($key, null);
    delete_option($key);
}
$oldUser = get_current_user_id();
wp_set_current_user(1);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$fixture = file_get_contents(dirname(__DIR__) . '/fixtures/museum.ics');
$body = $fixture;
$mode = 'ok';
$requests = 0;
$lastUrl = '';
$mock = static function ($pre, $args, $url) use (&$body, &$mode, &$requests, &$lastUrl, $check) {
    if (!str_contains($url, 'museum-fixture')) {
        return $pre;
    }
    $requests++;
    $lastUrl = $url;
    $check($args['reject_unsafe_urls'] === true && $args['redirection'] === 0, 'HTTPのオプションが安全。');
    $check($args['limit_response_size'] === Parser::MAX_BYTES + 1, '応答の大きさに上限がある。');
    if ($mode === 'timeout') {
        return new WP_Error('timeout', 'Secret URL must never leak: ' . $url);
    }
    return ['response' => ['code' => $mode === 'http_error' ? 403 : 200], 'body' => $body, 'headers' => []];
};
add_filter('pre_http_request', $mock, 10, 3);
$url = 'https://calendar.google.com/calendar/ical/museum-fixture%40group.calendar.google.com/public/basic.ics';
$id = 'museum-fixture@group.calendar.google.com';
// iCal形式の非公開URLの、作った秘密の部分。Googleが発行するのは16進数32桁。
$token = '0123456789abcdef0123456789abcdef';
$privateUrl = 'https://calendar.google.com/calendar/ical/museum-fixture%40group.calendar.google.com/private-' . $token . '/basic.ics';
$feed = new FeedRepository();
try {
    foreach ([
        'https://calendar.google.com/calendar/ical/museum-fixture%40group.calendar.google.com/private-not_a_token/basic.ics',
        'https://calendar.google.com/calendar/ical/museum-fixture%40group.calendar.google.com/private-' . $token . '/extra/basic.ics',
        'http://calendar.google.com/calendar/ical/museum-fixture%40group.calendar.google.com/private-' . $token . '/basic.ics',
        'http://calendar.google.com/calendar/ical/museum-fixture%40group.calendar.google.com/public/basic.ics',
        'https://127.0.0.1/calendar/ical/museum-fixture%40group.calendar.google.com/public/basic.ics',
        'https://calendar.google.com/calendar/embed?src=museum-fixture%40group.calendar.google.com',
    ] as $badUrl) {
        $error = Settings::save(['url' => $badUrl]);
        $check(is_wp_error($error) && !str_contains($error->get_error_message(), 'museum-fixture') && !str_contains($error->get_error_message(), $token), 'GoogleカレンダーのURL以外を受け付けず、エラーでURLを繰り返さない。');
    }
    $check(Settings::calendar() === null && get_option(Settings::SECRET_OPTION, null) === null && $requests === 0, '拒否したURLを保存も取得もしない。');
    $check(Settings::save(['url' => $url]) === true && Settings::calendar()?->id === $id && get_option(Settings::SECRET_OPTION, null) === null, '公開URLを受け付け、秘密の部分を保存しない。');
    $first = $feed->load();
    $check($first['body'] === $fixture && !$first['stale'], '最初の取得が成功する。');
    $feed->load();
    $check($requests === 1, '新しいキャッシュがあれば、HTTPのリクエストをもう一度送らない。');
    $check(Settings::save(['url' => Settings::calendar()->feedUrl()]) === true && get_option(FeedRepository::CACHE, null) !== null, '同じカレンダーのiCal形式のURLでは予定を捨てない。');
    $before = $requests;
    $check(Settings::saveAndRefresh(['url' => $url]) === true && $requests === $before + 1, '設定を保存すると、すぐにカレンダーを取得する。');
    $mode = 'timeout';
    $check(Settings::saveAndRefresh(['url' => $url]) === true && $requests === $before + 2 && Settings::calendar()?->id === $id, '取得に失敗しても設定は保存したまま。');
    $check((get_option(FeedRepository::CACHE)['error'] ?? '') !== '', '保存後の取得の失敗を設定画面のために記録する。');
    $mode = 'ok';
    $check(is_wp_error(Settings::saveAndRefresh(['url' => 'https://example.com/'])) && $requests === $before + 2, '拒否したURLでは取得しない。');
    $check(Settings::saveAndRefresh(['url' => $url]) === true && $requests === $before + 3 && get_option(FeedRepository::CACHE)['error'] === '', '保存し直すと、前の取得のエラーを消す。');
    $html = do_shortcode('[museum_calendar month="2026-11"]');
    $check(str_contains($html, '日時変更ガイド') && str_contains($html, '終日展示'), 'ICSの予定を表示する。');
    $check(!str_contains($html, 'museum-fixture'), 'カレンダーのURLがページに出ない。');

    ob_start();
    Settings::page();
    $adminHtml = ob_get_clean();
    $check(str_contains($adminHtml, '<input type="password" id="museum-calendar-url" name="url" value="" class="large-text code" autocomplete="new-password" spellcheck="false"'), '設定の入力欄は空のパスワード欄。');
    $check(str_contains($adminHtml, '<p id="museum-calendar-connection">登録状況：公開URLを登録済み</p>') && str_contains($adminHtml, '<input type="checkbox" name="disconnect" value="1"> 接続を解除する</label>'), '設定画面は公開URLを登録済みと表示し、接続の解除を出す。');
    $check(!str_contains($adminHtml, 'museum-fixture') && !str_contains($adminHtml, 'calendar.google.com'), '設定画面にカレンダーのIDもURLも出ない。');
    $check(str_contains($adminHtml, '_wpnonce') && preg_match('#<p>最終取得：\d{4}/\d{2}/\d{2} \d{2}:\d{2}</p>#', $adminHtml) === 1 && !str_contains($adminHtml, '取得失敗：'), '管理画面のフォームと取得状況がある。');

    // 同じカレンダーでも、iCal形式の非公開URLは別の取得先になる。
    $check(Settings::save(['url' => $privateUrl]) === true && get_option(FeedRepository::CACHE, null) === null, 'カレンダーを公開URLから非公開URLに切り替えると、公開URLの予定を捨てる。');
    $check(get_option(Settings::OPTION) === $id && get_option(Settings::SECRET_OPTION) === $token && Settings::calendar()?->secret === $token, '非公開URLはカレンダーIDと秘密の部分を保存する。');
    $check(!array_key_exists(Settings::SECRET_OPTION, wp_load_alloptions()), '秘密の部分はautoloadしない。');
    $before = $requests;
    $check($feed->load()['body'] === $fixture && $requests === $before + 1 && $lastUrl === $privateUrl, '非公開のカレンダーは非公開URLから取得する。');
    $check(Settings::saveAndRefresh(['url' => " {$privateUrl}\n"]) === true && $requests === $before + 2 && get_option(FeedRepository::CACHE, null) !== null, '同じ非公開URLを保存し直すと、予定を保ち、取得し直す。');
    $check(Settings::saveAndRefresh(['url' => '']) === true && $requests === $before + 3 && $lastUrl === $privateUrl, '空の入力欄では、接続したカレンダーを更新する。');
    $check(get_option(Settings::OPTION) === $id && get_option(Settings::SECRET_OPTION) === $token && get_option(FeedRepository::CACHE, null) !== null, '空の入力欄では、接続と予定を保つ。');
    ob_start();
    Settings::page();
    $adminHtml = ob_get_clean();
    $check(str_contains($adminHtml, '<p id="museum-calendar-connection">登録状況：非公開URLを登録済み</p>') && str_contains($adminHtml, 'name="url" value=""'), '設定画面は非公開URLを登録済みと表示し、入力欄は空。');
    $pages = $adminHtml . do_shortcode('[museum_calendar month="2026-11"]') . do_shortcode('[museum_calendar_today]') . do_shortcode('[museum_calendar_today lang="en"]');
    foreach ([$token, 'private-', 'museum-fixture', 'calendar.google.com'] as $secret) {
        $check(!str_contains($pages, $secret), '設定画面にも公開ページにも、非公開URLとカレンダーIDが出ない。');
    }
    $mode = 'timeout';
    $failed = $feed->load(true);
    $mode = 'ok';
    $check($failed['stale'] && $failed['error'] === FeedRepository::FAILURE && !str_contains(serialize(get_option(FeedRepository::CACHE)), $token), '非公開URLの取得に失敗したときは、汎用のエラーだけを保存する。');
    $check(str_contains(FeedRepository::FAILURE, '非公開URL') && str_contains(FeedRepository::FAILURE, '公開URL'), 'エラーは非公開URLと公開URLの両方に当てはまる。');
    $check(Settings::save(['url' => $url]) === true && get_option(Settings::SECRET_OPTION, null) === null && get_option(FeedRepository::CACHE, null) === null, '公開URLに戻すと、秘密の部分と非公開URLの予定を削除する。');
    $before = $requests;
    $check($feed->load()['body'] === $fixture && $requests === $before + 1 && $lastUrl === Settings::calendar()->feedUrl() && str_contains($lastUrl, '/public/basic.ics'), '公開カレンダーは公開URLから取得する。');

    $mode = 'timeout';
    $stale = $feed->load(true);
    $check($stale['stale'] && $stale['body'] === $fixture, '取得に失敗しても、最後に成功したデータを捨てない。');
    $check(!str_contains($stale['error'], 'museum-fixture'), 'エラーにカレンダーのURLが出ない。');
    $tokyo = new DateTimeZone('Asia/Tokyo');
    $fetched = (new DateTimeImmutable('@' . $stale['fetched_at']))->setTimezone($tokyo)->format('Y/m/d H:i');
    $staleJapanese = '更新を確認できていないため、前回取得した予定を表示しています。最終取得：' . $fetched . '（日本時間）';
    $staleEnglish = 'Updates could not be confirmed, so the previously fetched schedule is shown. Last fetched: ' . $fetched . ' (Japan time)';
    $html = do_shortcode('[museum_calendar month="2026-11"]');
    $check(str_starts_with($html, '<section class="museum-calendar" id="museum-calendar" aria-label="月間カレンダー">' . "\n" . '<p class="museum-calendar__notice" role="status">' . $staleJapanese . '</p>' . "\n"), '前回データは、その案内と最終取得の時刻とともに表示する。');
    $check(str_contains($html, '<li data-title="一日休館" data-kind="other"><strong>一日休館</strong><span>終日</span></li>') && !str_contains($html, ' lang='), 'langのない月はICSの予定を従来どおりに出す。');
    $html = do_shortcode('[museum_calendar month="2026-11" lang="en"]');
    $check(str_starts_with($html, '<section class="museum-calendar" id="museum-calendar" aria-label="Monthly calendar" lang="en">' . "\n" . '<p class="museum-calendar__notice" role="status">' . $staleEnglish . '</p>' . "\n"), '英語の月は、前回データであることと取得の時刻を英語で出す。');
    $check(str_contains($html, '<li data-title="一日休館" data-kind="other"><strong lang="ja">一日休館</strong><span>All day</span></li>'), '英語の月は、英語での表示のないICSのタイトルを登録したとおりに出す。');
    $check(str_contains($html, '<li data-title="日時変更ガイド" data-kind="other"><strong lang="ja">日時変更ガイド</strong><span>14:00–15:00</span></li>'), '英語の月はICSの予定の時刻を保つ。');
    $check(!str_contains($html, '終日<') && !str_contains($html, '前回取得') && !str_contains($html, 'museum-fixture'), '英語の月に、プラグインの日本語の文言もカレンダーのURLも出ない。');
    $html = do_shortcode('[museum_calendar_today lang="en"]');
    $check(str_starts_with($html, '<section class="museum-calendar-today" aria-label="Today&#039;s schedule" lang="en">' . "\n" . '<p class="museum-calendar-today__notice" role="status">' . $staleEnglish . '</p>' . "\n"), '英語の本日は、前回データであることを英語で出す。');
    $before = $requests;
    $feed->load();
    $check($requests === $before, '失敗後の再試行の間隔を守る。');

    $now = new DateTimeImmutable('now', $tokyo);
    $received = static function (string $html, string ...$lines) use ($check): void {
        foreach ($lines as $line) {
            $check(preg_match('/^' . preg_quote(esc_html($line), '/') . '$/m', $html) === 1, "テーマのテンプレートが受け取る: {$line}");
        }
    };
    $inJapanese = require dirname(__DIR__) . '/support/japanese-locale.php';
    $linked = static function (string $html, array $links) use ($check): void {
        foreach ($links as $name => [$target, $label]) {
            $check(preg_match("/^{$name}={$target} \\[{$label}\\] \\S*[?;]museum_month={$target}#museum-calendar$/m", $html) === 1, "テーマの月のテンプレートが {$name} の月のリンクをラベル {$label} とともに受け取る。");
        }
    };
    $check(wp_get_theme('override-theme')->exists(), 'テスト環境にフィクスチャーのテーマがある。');
    $theme = get_stylesheet();
    $startOfWeek = get_option('start_of_week');
    $dateFormat = get_option('date_format');
    switch_theme('override-theme');
    try {
        update_option('start_of_week', 1);
        $month = do_shortcode('[museum_calendar month="2026-11"]');
        $check(preg_match('/^<section class="museum-calendar" id="museum-calendar" aria-label="月間カレンダー">\s*<p class="museum-calendar__notice" role="status">[^<]*前回取得した予定[^<]*<\/p>\s*<pre class="override-month">/', $month) === 1
            && str_ends_with(rtrim($month), '</section>'), 'テーマの月のテンプレートは、プラグインのsectionの中で、前回データの案内の後に出る。');
        $check(!str_contains($month, '<table'), 'テーマの月のテンプレートが既定のテンプレートの代わりになる。');
        $received(
            $month,
            'variables=month,label,weekdays,weeks,previous,next,empty,lang,text',
            'this=hidden',
            'scope=none',
            'lang=ja',
            'text=scroll_label: 月間予定表（横にスクロールできます） | empty: この月の予定はありません。',
            'month=2026-11',
            'label=November 2026',
            'weekdays=1Mon,2Tue,3Wed,4Thu,5Fri,6Sat,7Sun',
            'previous=none',
            'next=none',
            'empty=no',
            'weeks=7,7,7,7,7,7',
            'outside=12',
            'row=-,-,-,-,-,-,1',
            'row=30,-,-,-,-,-,-',
            'cell=2026-11-01 day=1 weekday=7 today=no',
            'event=2026-11-10 [一日休館] [終日] all-day [] []',
            'label=2026-11-10 [一日休館] [一日休館] []',
            'kind=2026-11-10 [一日休館] [other]',
            'event=2026-11-17 [日時変更ガイド] [14:00–15:00] timed [14:00] [15:00]',
            'event=2026-11-30 [月末夜間] [23:00–24:00] timed [23:00] [24:00]',
        );
        $englishMonth = do_shortcode('[museum_calendar month="2026-11" lang="en"]');
        $check(preg_match('/^<section class="museum-calendar" id="museum-calendar" aria-label="Monthly calendar" lang="en">\s*<p class="museum-calendar__notice" role="status">' . preg_quote($staleEnglish, '/') . '<\/p>\s*<pre class="override-month">/', $englishMonth) === 1
            && str_ends_with(rtrim($englishMonth), '</section>'), 'テーマの月のテンプレートは、英語のsectionの中で、前回データの案内の後に出る。');
        $received(
            $englishMonth,
            'variables=month,label,weekdays,weeks,previous,next,empty,lang,text',
            'lang=en',
            'text=scroll_label: Monthly schedule (scrolls sideways) | empty: No events this month.',
            'label=November 2026',
            'weekdays=1Mon,2Tue,3Wed,4Thu,5Fri,6Sat,7Sun',
            'event=2026-11-10 [一日休館] [All day] all-day [] []',
            'label=2026-11-10 [一日休館] [一日休館] [ja]',
            'event=2026-11-17 [日時変更ガイド] [14:00–15:00] timed [14:00] [15:00]',
            'label=2026-11-17 [日時変更ガイド] [日時変更ガイド] [ja]',
        );
        $described = [
            'description=2026-11-26 [説明付きの催し] "午後の演奏会\n曲目：A\nB\nC\nD\n雨天決行 & 予約不要\n定員20名"',
            'description=2026-11-24 [場所付きの催し] "受付で名前をお伝えください。\n持ち物：筆記用具, 上履き; 飲み物"',
            'description=2026-11-28 [長い説明の催し] "' . str_repeat('あいうえおかきくけこ', 50) . '…"',
            'description=2026-11-10 [一日休館] ""',
            'url=2026-11-26 [説明付きの催し] [https://example.com/concert]',
            'url=2026-11-24 [場所付きの催し] []',
        ];
        $received($month, ...$described);
        $received($englishMonth, ...$described);
        $concertDay = new DateTimeImmutable('2026-11-26 12:00', $tokyo);
        $meetingDay = new DateTimeImmutable('2026-11-24 12:00', $tokyo);
        $themeToday = [
            (new Renderer(new Source($fixture), KindTable::defaults()))->renderToday($concertDay, 'n/j'),
            (new Renderer(new Source($fixture), KindTable::defaults(), '', Language::English))->renderToday($concertDay, 'D, M j'),
            (new Renderer(new Source($fixture), KindTable::defaults()))->renderToday($meetingDay, 'n/j'),
        ];
        foreach (['説明付きの催し', '午後の演奏会', 'example.com', '場所付きの催し', '受付で名前'] as $event) {
            $check(!str_contains(implode('', $themeToday), $event), "テーマの本日のテンプレートは当日の予定を受け取らない: {$event}");
        }
        $themed = $month . $englishMonth . implode('', $themeToday);
        foreach (['&lt;script', '&lt;style', '&lt;b&gt;', '&lt;p&gt;', '&lt;br', '&lt;a ', '&lt;li', 'alert(', 'color: red'] as $markup) {
            $check(!str_contains($themed, $markup), "テーマのテンプレートが説明のマークアップを受け取らない: {$markup}");
        }
        $facility = new Source(implode("\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            "BEGIN:VEVENT\nUID:close\nDTSTART;VALUE=DATE:20261110\nSUMMARY:休館日\nEND:VEVENT",
            "BEGIN:VEVENT\nUID:tea\nDTSTART;TZID=Asia/Tokyo:20261111T120000\nDTEND;TZID=Asia/Tokyo:20261111T160000\nSUMMARY:お茶室営業日\nEND:VEVENT",
            "BEGIN:VEVENT\nUID:untitled\nDTSTART;VALUE=DATE:20261112\nEND:VEVENT",
            'END:VCALENDAR',
        ]));
        $tuesdayNoon = new DateTimeImmutable('2026-11-10 12:00', $tokyo);
        $received(
            (new Renderer($facility, KindTable::defaults(), '', Language::English))->render(new Month('2026-11'), $tuesdayNoon),
            'lang=en',
            'event=2026-11-10 [休館日] [All day] all-day [] []',
            'label=2026-11-10 [休館日] [CLOSED] []',
            'kind=2026-11-10 [休館日] [closed]',
            'event=2026-11-11 [お茶室営業日] [12:00–16:00] timed [12:00] [16:00]',
            'label=2026-11-11 [お茶室営業日] [Tea Room Open] []',
            'kind=2026-11-11 [お茶室営業日] [tea]',
            'label=2026-11-12 [（無題）] [(Untitled)] []',
            'kind=2026-11-12 [（無題）] [other]',
        );
        $received(
            (new Renderer($facility, KindTable::defaults()))->render(new Month('2026-11'), $tuesdayNoon),
            'lang=ja',
            'event=2026-11-10 [休館日] [終日] all-day [] []',
            'label=2026-11-10 [休館日] [休館日] []',
            'kind=2026-11-10 [休館日] [closed]',
            'label=2026-11-11 [お茶室営業日] [お茶室営業日] []',
            'kind=2026-11-11 [お茶室営業日] [tea]',
            'label=2026-11-12 [（無題）] [（無題）] []',
            'kind=2026-11-12 [（無題）] [other]',
        );
        $received(
            (new Renderer($facility, KindTable::defaults(), '', Language::English))->renderToday($tuesdayNoon, 'D, M j'),
            'lang=en',
            'text=empty: No opening information today.',
            'date=Tue, Nov 10',
            'status=closed [CLOSED] []',
        );
        $received((new Renderer($facility, KindTable::defaults()))->renderToday($tuesdayNoon, 'n/j'), 'lang=ja', 'status=closed [休館日] []');
        $inJapanese(static function () use ($received, $check): string {
            $received(do_shortcode('[museum_calendar month="2026-11" lang="en"]'), 'lang=en', 'label=November 2026', 'weekdays=1Mon,2Tue,3Wed,4Thu,5Fri,6Sat,7Sun');
            $check(determine_locale() === 'ja', '英語の月はサイトのロケールに戻す。');
            $received(do_shortcode('[museum_calendar month="2026-11"]'), 'lang=ja', 'label=11月 2026', 'weekdays=1月,2火,3水,4木,5金,6土,7日');
            return '';
        });
        $december = (new Renderer(new Source($fixture), KindTable::defaults()))->render(new Month('2026-12'), new DateTimeImmutable('2026-11-30 15:30', new DateTimeZone('UTC')));
        $received(
            $december,
            'month=2026-12',
            'label=December 2026',
            'empty=no',
            'weeks=7,7,7,7,7',
            'outside=4',
            'cell=2026-12-01 day=1 weekday=2 today=yes',
            'cell=2026-12-02 day=2 weekday=3 today=no',
            'cell=2026-12-31 day=31 weekday=4 today=no',
        );
        $linked($december, ['previous' => ['2026-11', 'November'], 'next' => ['2027-01', 'January']]);
        $japanese = $inJapanese(static fn (): string => (new Renderer(new Source($fixture), KindTable::defaults()))->render(new Month('2026-12'), $now));
        $received($japanese, 'label=12月 2026', 'weekdays=1月,2火,3水,4木,5金,6土,7日');
        $linked($japanese, ['previous' => ['2026-11', '11月'], 'next' => ['2027-01', '1月']]);
        $check(substr_count($december, 'today=yes') === 1, '本日の印を付けるセルはちょうど1つ。');
        $received((new Renderer(new Source("BEGIN:VCALENDAR\nVERSION:2.0\nEND:VCALENDAR"), KindTable::defaults()))->render(new Month('2026-11'), $now), 'empty=yes');

        update_option('start_of_week', 0);
        $received(
            do_shortcode('[museum_calendar month="2026-11"]'),
            'weekdays=7Sun,1Mon,2Tue,3Wed,4Thu,5Fri,6Sat',
            'weeks=7,7,7,7,7',
            'outside=5',
            'row=1,2,3,4,5,6,7',
            'row=29,30,-,-,-,-,-',
            'cell=2026-11-01 day=1 weekday=7 today=no',
            'cell=2026-11-02 day=2 weekday=1 today=no',
            'cell=2026-11-07 day=7 weekday=6 today=no',
            'event=2026-11-10 [一日休館] [終日] all-day [] []',
        );
        $received(
            $inJapanese(static fn (): string => do_shortcode('[museum_calendar month="2026-07"]')),
            'weekdays=7日,1月,2火,3水,4木,5金,6土',
            'row=-,-,-,1,2,3,4',
            'row=26,27,28,29,30,31,-',
            'cell=2026-07-01 day=1 weekday=3 today=no',
            'cell=2026-07-05 day=5 weekday=7 today=no',
        );

        update_option('date_format', 'Y年n月j日');
        $today = do_shortcode('[museum_calendar_today]');
        $check(preg_match('/^<section class="museum-calendar-today" aria-label="本日の予定">\s*<p class="museum-calendar-today__notice" role="status">[^<]*前回取得した予定[^<]*<\/p>\s*<pre class="override-today">/', $today) === 1
            && str_ends_with(rtrim($today), '</section>'), 'テーマの本日のテンプレートは、プラグインのsectionの中で、前回データの案内の後に出る。');
        $check(!str_contains($today, 'museum-calendar-today__date'), 'テーマの本日のテンプレートが既定のテンプレートの代わりになる。');
        $received(
            $today,
            'variables=today,date,weekday,status,lang,text',
            'this=hidden',
            'scope=none',
            'lang=ja',
            'text=empty: 本日の開館情報はありません。',
            'today=' . $now->format('Y-m-d') . ' Asia/Tokyo',
            'date=' . $now->format('Y年n月j日'),
            'weekday=' . ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][(int) $now->format('N') - 1],
        );
        $received($inJapanese(static fn (): string => do_shortcode('[museum_calendar_today]')), 'weekday=' . ['月', '火', '水', '木', '金', '土', '日'][(int) $now->format('N') - 1]);
        $englishToday = $inJapanese(static fn (): string => do_shortcode('[museum_calendar_today lang="en"]'));
        $check(preg_match('/^<section class="museum-calendar-today" aria-label="Today&#039;s schedule" lang="en">\s*<p class="museum-calendar-today__notice" role="status">' . preg_quote($staleEnglish, '/') . '<\/p>\s*<pre class="override-today">/', $englishToday) === 1
            && str_ends_with(rtrim($englishToday), '</section>'), 'テーマの本日のテンプレートは、英語のsectionの中で、前回データの案内の後に出る。');
        $received(
            $englishToday,
            'variables=today,date,weekday,status,lang,text',
            'lang=en',
            'text=empty: No opening information today.',
            'today=' . $now->format('Y-m-d') . ' Asia/Tokyo',
            'date=' . $now->format('D, M j'),
            'weekday=' . $now->format('D'),
        );
        $renderTuesday = static fn (): string => (new Renderer(new Source($fixture), KindTable::defaults()))->renderToday(new DateTimeImmutable('2026-11-09 15:30', new DateTimeZone('UTC')), 'n/j H:i');
        $received($inJapanese($renderTuesday), 'weekday=火');
        $tuesday = $renderTuesday();
        $received(
            $tuesday,
            'today=2026-11-10 Asia/Tokyo',
            'date=11/10 00:30',
            'weekday=Tue',
            'status=none',
        );
        $check(!str_contains($tuesday, '一日休館') && !str_contains($tuesday, '第2火曜講座'), '開館状況のない日も、テーマの本日のテンプレートはその日の予定を受け取らない。');
        foreach (['museum-fixture', 'calendar.google.com'] as $secret) {
            $check(!str_contains($month . $december . $today . $tuesday . $englishMonth . $englishToday, $secret), 'テーマのテンプレートはカレンダーのURLを読めない。');
        }

        add_filter('museum_calendar_fixture_throws', '__return_true');
        try {
            $level = ob_get_level();
            $html = do_shortcode('[museum_calendar month="2026-11"]') . do_shortcode('[museum_calendar_today]');
            $check(str_contains($html, 'この月の予定を表示できません') && str_contains($html, '本日の予定を表示できません'), '失敗したテーマのテンプレートは、そのショートコードの案内を出す。');
            $check(!str_contains($html, 'output') && !str_contains($html, '<section') && ob_get_level() === $level, '失敗したテーマのテンプレートは途中までの出力を漏らさない。');
            $inJapanese(static function () use ($check, $level, $tokyo): string {
                $html = do_shortcode('[museum_calendar month="2026-11" lang="en"]') . do_shortcode('[museum_calendar_today lang="en"]');
                $check($html === '<p class="museum-calendar-notice" role="status" lang="en">The schedule for this month cannot be shown.</p>'
                    . '<p class="museum-calendar-notice" role="status" lang="en">Today&#039;s schedule cannot be shown.</p>', '失敗したテーマのテンプレートは、そのショートコードの英語の案内だけを出す。');
                $check(determine_locale() === 'ja' && ob_get_level() === $level, '英語で失敗したテーマのテンプレートは、サイトのロケールに戻す。');
                $check(wp_date('D F', (new DateTimeImmutable('2026-11-15 12:00', $tokyo))->getTimestamp(), $tokyo) === '日 11月', '英語で失敗したテーマのテンプレートは英語の名前を残さない。');
                return '';
            });
        } finally {
            remove_filter('museum_calendar_fixture_throws', '__return_true');
        }
    } finally {
        update_option('start_of_week', $startOfWeek);
        update_option('date_format', $dateFormat);
        switch_theme($theme);
    }
    $check(get_stylesheet() === $theme && str_contains(do_shortcode('[museum_calendar month="2026-11"]'), '<table'), 'テーマを戻した後は既定のテンプレートに戻る。');
    $plain = implode('', [
        (new Renderer(new Source($fixture), KindTable::defaults()))->render(new Month('2026-11'), $meetingDay),
        (new Renderer(new Source($fixture), KindTable::defaults(), '', Language::English))->render(new Month('2026-11'), $meetingDay),
    ]);
    $plainToday = implode('', [
        (new Renderer(new Source($fixture), KindTable::defaults()))->renderToday($meetingDay, 'n/j'),
        (new Renderer(new Source($fixture), KindTable::defaults(), '', Language::English))->renderToday($meetingDay, 'D, M j'),
        (new Renderer(new Source($fixture), KindTable::defaults()))->renderToday($concertDay, 'n/j'),
    ]);
    $check(substr_count($plain, '<li data-title="場所付きの催し"') === 2 && str_contains($plain, '<li data-title="説明付きの催し"'), '既定のテンプレートは説明のある予定を表示する。');
    $check(!str_contains($plainToday, '<ul') && !str_contains($plainToday, '<li') && !str_contains($plainToday, '場所付きの催し') && !str_contains($plainToday, '説明付きの催し'), '既定の本日のテンプレートは、開館状況のほかに予定を出さない。');
    $linked = '/<li data-title="説明付きの催し" data-kind="other"><a href="https:\/\/example\.com\/concert"><strong(?: lang="ja")?>説明付きの催し<\/strong><\/a><span>13:00–14:00<\/span><\/li>/';
    $check(preg_match_all($linked, $plain) === 2, '既定のテンプレートは、URLのある予定の名前を、日本語と英語の月間カレンダーでリンクにする。');
    $check(preg_match_all('/<li [^>]*><a /', $plain) === 2, '既定のテンプレートは、URLのない予定の名前をリンクにしない。');
    $quoted = new Source(implode("\n", [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        "BEGIN:VEVENT\nUID:quoted\nDTSTART;TZID=Asia/Tokyo:20261126T130000\nDTEND;TZID=Asia/Tokyo:20261126T140000\nSUMMARY:説明付きの催し\nDESCRIPTION:https://example.com/?a=1&b=\"'>x\nEND:VEVENT",
        'END:VCALENDAR',
    ]));
    $escaped = (new Renderer($quoted, KindTable::defaults()))->render(new Month('2026-11'), $meetingDay);
    $check(substr_count($escaped, '<a href="https://example.com/?a=1&#038;b=&#039;x"><strong>説明付きの催し</strong></a>') === 1 && !str_contains($escaped, '"\'>'), '既定のテンプレートは予定のURLをエスケープする。');
    foreach (['受付で名前', '午後の演奏会', 'あいうえおかきくけこ', '定員20名'] as $description) {
        $check(!str_contains($plain . $plainToday, $description), "既定のテンプレートは説明を出さない: {$description}");
    }
    foreach (['非公開の会議室', 'location-7f3a', 'attendee-91c2', 'organizer-5d8e', 'attachment-3b6f'] as $private) {
        $check(!str_contains($plain . $plainToday . $themed, $private), "場所、参加者、主催者、添付はページに出ない: {$private}");
    }

    $mode = 'timeout';
    $before = $requests;
    update_option(FeedRepository::CACHE, ['body' => $fixture, 'fetched_at' => time(), 'attempted_at' => time(), 'error' => '', 'hash' => hash('sha256', Settings::calendar()->feedUrl())], false);
    switch_theme('override-theme');
    try {
        $received(do_shortcode('[museum_calendar month="2026-11"]'), ...$described);
    } finally {
        switch_theme($theme);
    }
    $check($requests === $before, '説明を表示する前に保存したキャッシュを、取得し直さずに使う。');
    $mode = 'ok';

    $regularWith = static fn (string $open, string $closed): Source => new Source(implode("\n", [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        "BEGIN:VEVENT\nUID:open\nDTSTART;TZID=Asia/Tokyo:20261101T103000\nDTEND;TZID=Asia/Tokyo:20261101T163000\nRRULE:FREQ=DAILY\nSUMMARY:{$open}\nEND:VEVENT",
        "BEGIN:VEVENT\nUID:close\nDTSTART;VALUE=DATE:20261117\nSUMMARY:{$closed}\nEND:VEVENT",
        "BEGIN:VEVENT\nUID:tour\nDTSTART;TZID=Asia/Tokyo:20261117T110000\nDTEND;TZID=Asia/Tokyo:20261117T120000\nSUMMARY:ガイドツアー開催日\nEND:VEVENT",
        'END:VCALENDAR',
    ]));
    $regular = $regularWith('開館日', '休館日');
    $cell = static function (string $html, string $date): array {
        preg_match('/<time datetime="' . $date . '">\d+<\/time>(.*?)<\/td>/s', $html, $match);
        preg_match_all('/<li data-title="([^"]*)"/', $match[1] ?? '', $titles);
        return $titles[1];
    };
    $statusOf = static function (string $html): ?string {
        return preg_match('/<p class="museum-calendar-today__status" data-kind="([^"]*)"(?: style="[^"]*")?><strong>([^<]*)<\/strong>(?:<span>([^<]*)<\/span>)?<\/p>/', $html, $match) === 1
            ? $match[1] . ' ' . $match[2] . ' ' . ($match[3] ?? '')
            : null;
    };
    $closedDay = new DateTimeImmutable('2026-11-17 12:00', $tokyo);
    $html = (new Renderer($regular, KindTable::defaults()))->render(new Month('2026-11'), $closedDay);
    $check($cell($html, '2026-11-16') === ['開館日'] && $cell($html, '2026-11-18') === ['開館日'], '繰り返しの開館日は、休館日のない日に出る。');
    $check($cell($html, '2026-11-17') === ['休館日', 'ガイドツアー開催日'], '繰り返しの開館日に重なる1日だけの休館日は、月間カレンダーでその開館日を隠す。');
    $html = (new Renderer($regular, KindTable::defaults()))->renderToday($closedDay, 'n/j');
    $check($statusOf($html) === 'closed 休館日 ' && !str_contains($html, '<li') && !str_contains($html, 'ガイドツアー開催日'), '繰り返しの開館日に重なる1日だけの休館日は、本日を休館にし、ほかの予定を出さない。');
    $check($statusOf((new Renderer($regular, KindTable::defaults()))->renderToday($closedDay->modify('+1 day'), 'n/j')) === 'open 開館日 10:30–16:30', '繰り返しの開館日は、ほかの日を開館にする。');
    $html = (new Renderer($regular, KindTable::defaults(), '', Language::English))->renderToday($closedDay, 'n/j');
    $check($statusOf($html) === 'closed CLOSED ' && $statusOf((new Renderer($regular, KindTable::defaults(), '', Language::English))->renderToday($closedDay->modify('+1 day'), 'n/j')) === 'open OPEN 10:30–16:30', '休館日と開館日の英語の開館状況は CLOSED と OPEN。');

    $state = get_option(FeedRepository::CACHE);
    $state['fetched_at'] = time() - 400 * DAY_IN_SECONDS;
    update_option(FeedRepository::CACHE, $state, false);
    $html = do_shortcode('[museum_calendar month="2026-11"]');
    $check(str_contains($html, '終日展示') && str_contains($html, '前回取得した予定を表示しています') && !str_contains($html, '現在、予定を取得できません'), '最後に取得した予定は、どれだけ古くても案内とともに表示し続ける。');
    $check(str_contains(do_shortcode('[museum_calendar_today]'), '前回取得した予定を表示しています'), '本日も、最後に取得した予定をどれだけ古くても使い続ける。');
    // 取得済みのデータが1つもなければ、表示できるものはない。
    $state['body'] = '';
    $state['fetched_at'] = 0;
    update_option(FeedRepository::CACHE, $state, false);
    $unavailable = '<p class="museum-calendar-notice" role="status"%s>%s</p>';
    foreach (['museum_calendar month="2026-11"', 'museum_calendar_today'] as $shortcode) {
        $check(do_shortcode("[{$shortcode}]") === sprintf($unavailable, '', '現在、予定を取得できません。時間をおいてご確認ください。'), "使える取得データのない [{$shortcode}] の案内が変わっていない。");
        $check(do_shortcode("[{$shortcode} lang=\"en\"]") === sprintf($unavailable, ' lang="en"', 'The schedule is currently unavailable. Please check again later.'), "[{$shortcode}] は使える取得データがないことを英語で案内する。");
    }

    $mode = 'ok';
    $feed->load(true);
    foreach (['<html>not a calendar</html>', str_repeat('x', Parser::MAX_BYTES + 1)] as $invalid) {
        $body = $invalid;
        $result = $feed->load(true);
        $check($result['body'] === $fixture && $result['stale'], '不正な応答で正しいキャッシュを置き換えない。');
    }
    $body = "BEGIN:VCALENDAR\nVERSION:2.0\nEND:VCALENDAR";
    $result = $feed->load(true);
    $check($result['body'] === $body && !$result['stale'], '空の取得データでは、削除された予定を消す。');
    $check(str_contains(do_shortcode('[museum_calendar month="2026-11"]'), 'この月の予定はありません'), '空のカレンダーの文言がある。');

    $body = implode("\n", [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'BEGIN:VEVENT',
        'UID:open-today',
        'DTSTART;TZID=Asia/Tokyo:' . $now->format('Ymd') . 'T103000',
        'DTEND;TZID=Asia/Tokyo:' . $now->format('Ymd') . 'T163000',
        'SUMMARY:開館日',
        'END:VEVENT',
        'END:VCALENDAR',
    ]);
    $feed->load(true);
    $mode = 'timeout';
    $check($feed->load(true)['stale'], '本日の開館日を含む取得データが前回データになる。');
    $html = do_shortcode('[museum_calendar_today lang="en"]');
    $check(str_contains($html, 'previously fetched schedule') && str_contains($html, '<p class="museum-calendar-today__status" data-kind="open" style="--museum-calendar-kind-color: #ffebee"><strong>OPEN</strong><span>10:30–16:30</span></p>'), '前回データは、前に取得した予定の開館状況を出す。');
    $mode = 'ok';

    $settingsPage = static function (): string {
        ob_start();
        Settings::page();
        return ob_get_clean();
    };
    $untyped = static function (string $html) use ($check): array {
        $section = explode('<h2>種類が未定義の予定名</h2>', $html);
        if (count($section) === 1) {
            // すべての予定名に種類があれば、この節は出ない。
            return ['titles' => [], 'complete' => true, 'unreadable' => false];
        }
        $check(count($section) === 2, '設定画面には、種類のないタイトルの節が1つある。');
        foreach (['museum-fixture', 'calendar.google.com', 'Secret URL', 'feed_unavailable'] as $secret) {
            $check(!str_contains($section[1], $secret), 'タイトルの一覧に、カレンダーのURLも取得の失敗の原因も出ない。');
        }
        preg_match_all('/<li>(.*?)<\/li>/s', $section[1], $items);
        return ['titles' => $items[1], 'complete' => false, 'unreadable' => str_contains($section[1], '予定を読み込めないため')];
    };
    $check($untyped($settingsPage()) === ['titles' => [], 'complete' => true, 'unreadable' => false], '予定のない取得データは、すべてのタイトルに種類がある取得データとして扱う。');
    $firstDay = new DateTimeImmutable($now->format('Y-m-01'), $tokyo);
    $allDayEvent = static fn (string $uid, DateTimeImmutable $day, string $summary = ''): string
        => "BEGIN:VEVENT\nUID:{$uid}\nDTSTART;VALUE=DATE:" . $day->format('Ymd') . ($summary === '' ? '' : "\nSUMMARY:{$summary}") . "\nEND:VEVENT";
    $calendar = static fn (array $events): string => implode("\n", ['BEGIN:VCALENDAR', 'VERSION:2.0', ...$events, 'END:VCALENDAR']);
    $typed = [
        $allDayEvent('open', $firstDay, '開館日'),
        $allDayEvent('close', $firstDay->modify('+1 day'), '休館日'),
        $allDayEvent('tea', $firstDay->modify('+2 days'), 'お茶室営業日'),
        $allDayEvent('tour', $firstDay->modify('+3 months'), 'ガイドツアー開催日'),
        $allDayEvent('wedding', $firstDay->modify('+6 months'), '結婚式'),
        $allDayEvent('evening', $firstDay->modify('+11 months'), '夜間特別開館'),
        $allDayEvent('concert', $firstDay->modify('+7 months'), 'コンサート'),
        $allDayEvent('event', $firstDay->modify('+8 months'), '　イベント　'),
        $allDayEvent('untitled', $firstDay->modify('+4 days')),
    ];
    $body = $calendar([
        ...$typed,
        $allDayEvent('before', $firstDay->modify('-1 day'), '先月の催し'),
        $allDayEvent('extra', $firstDay->modify('+5 days'), '臨時休館'),
        $allDayEvent('spaced', $firstDay->modify('+6 days'), '  臨時休館 '),
        $allDayEvent('again', $firstDay->modify('+2 months'), '臨時休館'),
        $allDayEvent('markup', $firstDay->modify('+11 months +20 days'), '<b>特別展</b>'),
        $allDayEvent('after', $firstDay->modify('+12 months'), '来年の催し'),
    ]);
    $feed->load(true);
    $adminHtml = $settingsPage();
    $check($untyped($adminHtml) === ['titles' => ['臨時休館', '&lt;b&gt;特別展&lt;/b&gt;'], 'complete' => false, 'unreadable' => false], '設定画面は、今後12か月の種類のないタイトルを、それぞれ1回ずつエスケープして並べ、ほかのタイトルを出さない。');
    $check(!str_contains($adminHtml, '<b>特別展'), 'タイトルの一覧にタイトルのマークアップが出ない。');
    $kindsForm = explode('<h2 id="museum-calendar-kinds">予定の種類と英語での表示</h2>', $adminHtml);
    $check(count($kindsForm) === 2 && str_contains($kindsForm[1], 'name="action" value="museum_calendar_save_kinds"') && str_contains($kindsForm[1], 'name="_wpnonce"'), '設定画面には、種類の表のための別のフォームがある。');
    $kindsForm = explode('</form>', $kindsForm[1])[0];
    $check(substr_count($kindsForm, '<input type="text" name="kinds[') === (count(KindTable::DEFAULTS) + 3) * 4, '種類のフォームは、既定の各行と3つの空の行を出す。');
    $check(str_contains($kindsForm, '<input type="color" value="#ffebee" aria-label="1行目の色を選ぶ"><input type="text" name="kinds[0][color]" value="#ffebee" aria-label="1行目の色" class="widefat code" placeholder="#e3f2fd" spellcheck="false">'), '行の色には、テキスト欄の前に名前のない色の選択がある。');
    $check(str_contains($kindsForm, '<input type="color" value="#ffffff" aria-label="9行目の色を選ぶ" data-empty><input type="text" name="kinds[8][color]" value=""') && substr_count($kindsForm, '<input type="color" ') === count(KindTable::DEFAULTS) + 3 && substr_count($kindsForm, ' data-empty>') === 3, '色のない行は、テキスト欄が空で、色の選択に空の印が付く。');
    $check(substr_count($kindsForm, '<script>') === 1 && str_contains($kindsForm, "addEventListener('input'"), '種類のフォームは、1つのスクリプトで色の選択とテキスト欄をそろえる。');
    $check(str_contains($kindsForm, 'name="kinds[0][title]" value="開館日" aria-label="1行目のタイトル"') && str_contains($kindsForm, 'name="kinds[7][english]" value="Event" aria-label="8行目の英語での表示"') && str_contains($kindsForm, 'name="kinds[10][kind]" value="" aria-label="11行目の種類"'), '種類のフォームの各入力に、値とアクセシブルな名前がある。');
    $check(str_contains($kindsForm, 'name="reset" value="1"> 既定の表に戻す</label>'), '種類のフォームは既定の表へのリセットを出す。');
    foreach (['museum-fixture', 'calendar.google.com'] as $secret) {
        $check(!str_contains($kindsForm, $secret), '種類のフォームにカレンダーが出ない。');
    }
    $calendarId = get_option(Settings::OPTION);
    $cache = get_option(FeedRepository::CACHE);
    $closedRow = ['title' => '臨時休館', 'kind' => 'closed', 'english' => 'Closed', 'color' => '#e26b6b'];
    $check(Settings::saveKinds(['kinds' => [...KindTable::DEFAULTS, $closedRow]]) === true, '2つ目の closed のタイトルを持つ種類の表を受け付ける。');
    $check(get_option(Settings::OPTION) === $calendarId && get_option(FeedRepository::CACHE) === $cache, '種類の表を保存しても、カレンダーとその予定は変わらない。');
    $adminHtml = $settingsPage();
    $check($untyped($adminHtml)['titles'] === ['&lt;b&gt;特別展&lt;/b&gt;'], '種類の表に足したタイトルは、種類のないタイトルの一覧から消える。');
    $check(str_contains($adminHtml, 'name="kinds[8][title]" value="臨時休館"') && substr_count($adminHtml, '<input type="text" name="kinds[') === (count(KindTable::DEFAULTS) + 4) * 4, '種類のフォームは保存した行を出す。');
    $check(str_contains($adminHtml, '<input type="color" value="#e26b6b" aria-label="9行目の色を選ぶ"><input type="text" name="kinds[8][color]" value="#e26b6b"') && substr_count($adminHtml, ' data-empty>') === 3, '種類のフォームは、保存した色を色の選択に出し、空の行だけに空の印を付ける。');
    $check(Settings::save(['url' => $url]) === true && get_option(Settings::KINDS_OPTION) === [...KindTable::DEFAULTS, $closedRow], 'カレンダーのURLを保存しても種類の表は変わらない。');
    $notice = static function (string $result) use ($settingsPage): string {
        $_GET['result'] = $result;
        try {
            return $settingsPage();
        } finally {
            unset($_GET['result']);
        }
    };
    $heading = '<h2 id="museum-calendar-kinds">予定の種類と英語での表示</h2>';
    $check(preg_match('/' . preg_quote($heading, '/') . '\s*<div class="notice notice-success inline"><p>予定の種類を保存しました。<\/p><\/div>/u', $notice('kinds_saved')) === 1, '種類の表の保存は、種類のフォームの見出しの下で知らせる。');
    foreach (['kinds_duplicate_title' => '同じタイトルの行が複数あります', 'kinds_invalid_kind' => 'other は使えません', 'kinds_too_long' => '200文字以内', 'kinds_too_many' => '100行まで', 'kinds_invalid_title' => '改行や制御文字', 'kinds_invalid_rows' => '入力内容を確認してください', 'kinds_forbidden' => null, 'kinds_<b>x</b>' => null] as $result => $text) {
        $html = $notice($result);
        $check($text === null
            ? !str_contains($html, 'notice-error') && !str_contains($html, '<b>x')
            : preg_match('/' . preg_quote($heading, '/') . '\s*<div class="notice notice-error inline"><p>予定の種類を保存できませんでした。[^<]*' . preg_quote($text, '/') . '/u', $html) === 1 && substr_count($html, 'notice-error') === 1, "結果 {$result} の案内が正しい。");
    }
    $check(str_contains($notice('saved'), '<div class="notice notice-success"><p>設定を保存しました。</p></div>') && str_contains($notice('invalid'), '<div class="notice notice-error"><p>URLを保存できませんでした。'), 'URLのフォームの案内は残る。');
    $check(!str_contains($notice('saved'), '予定の種類を保存しました') && !str_contains($notice('kinds_saved'), '設定を保存しました。'), '2つのフォームの案内は分かれたまま。');
    $check(Settings::saveKinds(['reset' => '1']) === true && get_option(Settings::KINDS_OPTION, null) === null, '種類の表をリセットする。');
    $body = $calendar($typed);
    $feed->load(true);
    $check($untyped($settingsPage()) === ['titles' => [], 'complete' => true, 'unreadable' => false], 'すべてのタイトルに種類があれば、設定画面にその節を出さない。');
    $mode = 'timeout';
    $state = get_option(FeedRepository::CACHE);
    $state['body'] = '';
    $state['fetched_at'] = 0;
    $state['attempted_at'] = 0;
    update_option(FeedRepository::CACHE, $state, false);
    $before = $requests;
    $adminHtml = $settingsPage();
    $check($requests === $before + 1 && $untyped($adminHtml) === ['titles' => [], 'complete' => false, 'unreadable' => true], '読めない取得データは、その節で知らせる。');
    foreach (['Secret URL', 'feed_unavailable'] as $secret) {
        $check(!str_contains($adminHtml, $secret), '設定画面に取得の失敗の原因が出ない。');
    }
    $check(preg_match('#<p role="alert">取得失敗：\d{4}/\d{2}/\d{2} \d{2}:\d{2}　更新に失敗しました。#', $adminHtml) === 1 && !str_contains($adminHtml, 'まだ取得していません'), '取得の失敗は、その時刻と汎用のエラーとともに表示する。');
    $mode = 'ok';

    Settings::save(['url' => 'https://calendar.google.com/calendar/ical/museum-fixture-another%40group.calendar.google.com/public/basic.ics']);
    $check(get_option(FeedRepository::CACHE, null) === null, '取得先を変えたら、前の取得先のデータを残さない。');
    $mode = 'http_error';
    $check(str_contains(do_shortcode('[museum_calendar]'), '現在、予定を取得できません'), '最初のHTTPの失敗を処理する。');
    $check(str_contains(do_shortcode('[museum_calendar_today]'), '現在、予定を取得できません'), '本日のショートコードはHTTPの失敗を処理する。');
    foreach (['', ' lang="en"'] as $lang) {
        $html = do_shortcode("[museum_calendar_today{$lang}]");
        $check(preg_match('/^<p class="museum-calendar-notice" role="status"[^>]*>[^<]*<\/p>$/', $html) === 1 && preg_match('/休館|開館|CLOSE|OPEN|__status/', $html) === 0, '使える取得データがなければ、本日は案内だけを出し、開館状況も休館の語も出さない。');
    }
    $mode = 'ok';
    $body = $fixture;
    $feed->load(true);
    $state = get_option(FeedRepository::CACHE);
    $state['attempted_at'] = time() - 901;
    update_option(FeedRepository::CACHE, $state, false);
    $before = $requests;
    $feed->load();
    $check($requests === $before + 1, '期限の切れたキャッシュを更新する。');
    $state = get_option(FeedRepository::CACHE);
    $state['body'] = 'not a calendar';
    update_option(FeedRepository::CACHE, $state, false);
    $html = do_shortcode('[museum_calendar_today]');
    $check(str_contains($html, '本日の予定を表示できません') && !str_contains($html, 'museum-fixture'), '本日のショートコードは読めない取得データを処理する。');

    wp_set_current_user(0);
    $check(is_wp_error(Settings::save(['disconnect' => '1'])), '権限のない設定の変更を許さない。');
    $check(Settings::calendar() !== null, '権限のないリクエストで設定が変わらない。');
    wp_set_current_user(1);
    $before = $requests;
    $check(Settings::saveAndRefresh(['url' => $privateUrl]) === true && Settings::calendar()?->secret === $token && get_option(FeedRepository::CACHE, null) !== null && $requests === $before + 1, '接続の解除の前に非公開URLが接続されている。');
    $check(Settings::saveAndRefresh(['url' => $privateUrl, 'disconnect' => '1']) === true && $requests === $before + 1, '接続を解除するときは、入力欄の内容にかかわらず取得しない。');
    $check(get_option(Settings::OPTION, null) === null && get_option(Settings::SECRET_OPTION, null) === null && get_option(FeedRepository::CACHE, null) === null, '接続を解除すると、カレンダーID、秘密の部分、予定を削除する。');
    $check(Settings::saveAndRefresh(['url' => '']) === true && Settings::calendar() === null && $requests === $before + 1, 'カレンダーのない空の入力欄は、何も変えず何も取得しない。');
    $adminHtml = $settingsPage();
    $check(!str_contains($adminHtml, '種類が未定義の予定名'), 'カレンダーがなければ、設定画面はタイトルを並べない。');
    $check(str_contains($adminHtml, '<p id="museum-calendar-connection">登録状況：未登録</p>') && !str_contains($adminHtml, 'name="disconnect"'), 'カレンダーがなければ、設定画面はそう表示し、接続の解除を出さない。');

} finally {
    remove_filter('pre_http_request', $mock, 10);
    foreach ($saved as $key => $value) {
        if ($value === null) {
            delete_option($key);
        } else {
            update_option($key, $value, false);
        }
    }
    wp_set_current_user($oldUser);
}
WP_CLI::success("ICSの統合テスト: {$checks} 件の検査に合格しました。");
