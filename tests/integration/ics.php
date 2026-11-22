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
    $check($args['reject_unsafe_urls'] === true && $args['redirection'] === 0, 'Unsafe HTTP options.');
    $check($args['limit_response_size'] === Parser::MAX_BYTES + 1, 'Missing response size limit.');
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
        $check(is_wp_error($error) && !str_contains($error->get_error_message(), 'museum-fixture') && !str_contains($error->get_error_message(), $token), 'A URL other than a Google Calendar URL was accepted, or its error repeated the URL.');
    }
    $check(Settings::calendar() === null && get_option(Settings::SECRET_OPTION, null) === null && $requests === 0, 'A rejected URL was saved or fetched.');
    $check(Settings::save(['url' => $url]) === true && Settings::calendar()?->id === $id && get_option(Settings::SECRET_OPTION, null) === null, 'The public URL was rejected, or it stored a secret.');
    $first = $feed->load();
    $check($first['body'] === $fixture && !$first['stale'], 'First fetch failed.');
    $feed->load();
    $check($requests === 1, 'Fresh cache made another HTTP request.');
    $check(Settings::save(['url' => Settings::calendar()->feedUrl()]) === true && get_option(FeedRepository::CACHE, null) !== null, 'The iCal address of the same calendar discarded its events.');
    $before = $requests;
    $check(Settings::saveAndRefresh(['url' => $url]) === true && $requests === $before + 1, 'Saving the settings must fetch the calendar at once.');
    $mode = 'timeout';
    $check(Settings::saveAndRefresh(['url' => $url]) === true && $requests === $before + 2 && Settings::calendar()?->id === $id, 'A failed fetch must leave the settings saved.');
    $check((get_option(FeedRepository::CACHE)['error'] ?? '') !== '', 'A failed fetch after saving must be recorded for the settings page.');
    $mode = 'ok';
    $check(is_wp_error(Settings::saveAndRefresh(['url' => 'https://example.com/'])) && $requests === $before + 2, 'A rejected URL must not fetch.');
    $check(Settings::saveAndRefresh(['url' => $url]) === true && $requests === $before + 3 && get_option(FeedRepository::CACHE)['error'] === '', 'Saving again must clear the error of the earlier fetch.');
    $html = do_shortcode('[museum_calendar month="2026-11"]');
    $check(str_contains($html, '日時変更ガイド') && str_contains($html, '終日展示'), 'ICS events were not rendered.');
    $check(!str_contains($html, 'museum-fixture'), 'The calendar URL reached the page.');

    ob_start();
    Settings::page();
    $adminHtml = ob_get_clean();
    $check(str_contains($adminHtml, '<input type="password" id="museum-calendar-url" name="url" value="" class="large-text code" autocomplete="new-password" spellcheck="false"'), 'The settings field must be an empty password field.');
    $check(str_contains($adminHtml, '<p id="museum-calendar-connection">登録状況：公開URLを登録済み</p>') && str_contains($adminHtml, '<input type="checkbox" name="disconnect" value="1"> 接続を解除する</label>'), 'The settings page must say that a public URL is connected, and offer to disconnect it.');
    $check(!str_contains($adminHtml, 'museum-fixture') && !str_contains($adminHtml, 'calendar.google.com'), 'The settings page printed the calendar ID or URL.');
    $check(str_contains($adminHtml, '_wpnonce') && preg_match('#<p>最終取得：\d{4}/\d{2}/\d{2} \d{2}:\d{2}</p>#', $adminHtml) === 1 && !str_contains($adminHtml, '取得失敗：'), 'Admin form/status missing.');

    // 同じカレンダーでも、iCal形式の非公開URLは別の取得先になる。
    $check(Settings::save(['url' => $privateUrl]) === true && get_option(FeedRepository::CACHE, null) === null, 'Switching a calendar from its public URL to its private URL must discard the public events.');
    $check(get_option(Settings::OPTION) === $id && get_option(Settings::SECRET_OPTION) === $token && Settings::calendar()?->secret === $token, 'The private URL must store the calendar ID and the secret.');
    $check(!array_key_exists(Settings::SECRET_OPTION, wp_load_alloptions()), 'The secret must not be autoloaded.');
    $before = $requests;
    $check($feed->load()['body'] === $fixture && $requests === $before + 1 && $lastUrl === $privateUrl, 'The private calendar must be fetched from its private URL.');
    $check(Settings::saveAndRefresh(['url' => " {$privateUrl}\n"]) === true && $requests === $before + 2 && get_option(FeedRepository::CACHE, null) !== null, 'Saving the same private URL again must keep the events and fetch again.');
    $check(Settings::saveAndRefresh(['url' => '']) === true && $requests === $before + 3 && $lastUrl === $privateUrl, 'An empty field must refresh the connected calendar.');
    $check(get_option(Settings::OPTION) === $id && get_option(Settings::SECRET_OPTION) === $token && get_option(FeedRepository::CACHE, null) !== null, 'An empty field must keep the connection and its events.');
    ob_start();
    Settings::page();
    $adminHtml = ob_get_clean();
    $check(str_contains($adminHtml, '<p id="museum-calendar-connection">登録状況：非公開URLを登録済み</p>') && str_contains($adminHtml, 'name="url" value=""'), 'The settings page must say that a private URL is connected, with an empty field.');
    $pages = $adminHtml . do_shortcode('[museum_calendar month="2026-11"]') . do_shortcode('[museum_calendar_today]') . do_shortcode('[museum_calendar_today lang="en"]');
    foreach ([$token, 'private-', 'museum-fixture', 'calendar.google.com'] as $secret) {
        $check(!str_contains($pages, $secret), 'The settings page or a public page printed the private URL or the calendar ID.');
    }
    $mode = 'timeout';
    $failed = $feed->load(true);
    $mode = 'ok';
    $check($failed['stale'] && $failed['error'] === FeedRepository::FAILURE && !str_contains(serialize(get_option(FeedRepository::CACHE)), $token), 'A failed fetch of the private URL must store only the generic error.');
    $check(str_contains(FeedRepository::FAILURE, '非公開URL') && str_contains(FeedRepository::FAILURE, '公開URL'), 'The error must cover a private and a public URL.');
    $check(Settings::save(['url' => $url]) === true && get_option(Settings::SECRET_OPTION, null) === null && get_option(FeedRepository::CACHE, null) === null, 'Switching back to the public URL must delete the secret and the private events.');
    $before = $requests;
    $check($feed->load()['body'] === $fixture && $requests === $before + 1 && $lastUrl === Settings::calendar()->feedUrl() && str_contains($lastUrl, '/public/basic.ics'), 'The public calendar must be fetched from its public URL.');

    $mode = 'timeout';
    $stale = $feed->load(true);
    $check($stale['stale'] && $stale['body'] === $fixture, 'Failed fetch discarded last good data.');
    $check(!str_contains($stale['error'], 'museum-fixture'), 'An error exposed the calendar URL.');
    $tokyo = new DateTimeZone('Asia/Tokyo');
    $fetched = (new DateTimeImmutable('@' . $stale['fetched_at']))->setTimezone($tokyo)->format('Y/m/d H:i');
    $staleJapanese = '更新を確認できていないため、前回取得した予定を表示しています。最終取得：' . $fetched . '（日本時間）';
    $staleEnglish = 'Updates could not be confirmed, so the previously fetched schedule is shown. Last fetched: ' . $fetched . ' (Japan time)';
    $html = do_shortcode('[museum_calendar month="2026-11"]');
    $check(str_starts_with($html, '<section class="museum-calendar" id="museum-calendar" aria-label="月間カレンダー">' . "\n" . '<p class="museum-calendar__notice" role="status">' . $staleJapanese . '</p>' . "\n"), 'Stale data was shown without its notice and the time of the last fetch.');
    $check(str_contains($html, '<li data-title="一日休館" data-kind="other"><strong>一日休館</strong><span>終日</span></li>') && !str_contains($html, ' lang='), 'A month without lang must print the ICS events as before.');
    $html = do_shortcode('[museum_calendar month="2026-11" lang="en"]');
    $check(str_starts_with($html, '<section class="museum-calendar" id="museum-calendar" aria-label="Monthly calendar" lang="en">' . "\n" . '<p class="museum-calendar__notice" role="status">' . $staleEnglish . '</p>' . "\n"), 'An English month must say in English that the data is stale and when it was fetched.');
    $check(str_contains($html, '<li data-title="一日休館" data-kind="other"><strong lang="ja">一日休館</strong><span>All day</span></li>'), 'An English month must print an ICS title without an English text as registered.');
    $check(str_contains($html, '<li data-title="日時変更ガイド" data-kind="other"><strong lang="ja">日時変更ガイド</strong><span>14:00–15:00</span></li>'), 'An English month must keep the hours of an ICS event.');
    $check(!str_contains($html, '終日<') && !str_contains($html, '前回取得') && !str_contains($html, 'museum-fixture'), 'An English month printed Japanese text of the plugin or the calendar URL.');
    $html = do_shortcode('[museum_calendar_today lang="en"]');
    $check(str_starts_with($html, '<section class="museum-calendar-today" aria-label="Today&#039;s schedule" lang="en">' . "\n" . '<p class="museum-calendar-today__notice" role="status">' . $staleEnglish . '</p>' . "\n"), 'An English today must say in English that the data is stale.');
    $before = $requests;
    $feed->load();
    $check($requests === $before, 'Failure retry backoff was ignored.');

    $now = new DateTimeImmutable('now', $tokyo);
    $received = static function (string $html, string ...$lines) use ($check): void {
        foreach ($lines as $line) {
            $check(preg_match('/^' . preg_quote(esc_html($line), '/') . '$/m', $html) === 1, "A theme template did not receive: {$line}");
        }
    };
    $inJapanese = require dirname(__DIR__) . '/support/japanese-locale.php';
    $linked = static function (string $html, array $links) use ($check): void {
        foreach ($links as $name => [$target, $label]) {
            $check(preg_match("/^{$name}={$target} \\[{$label}\\] \\S*[?;]museum_month={$target}#museum-calendar$/m", $html) === 1, "A theme month template did not receive the {$name} month link with the label {$label}.");
        }
    };
    $check(wp_get_theme('override-theme')->exists(), 'The fixture theme is missing from the test environment.');
    $theme = get_stylesheet();
    $startOfWeek = get_option('start_of_week');
    $dateFormat = get_option('date_format');
    switch_theme('override-theme');
    try {
        update_option('start_of_week', 1);
        $month = do_shortcode('[museum_calendar month="2026-11"]');
        $check(preg_match('/^<section class="museum-calendar" id="museum-calendar" aria-label="月間カレンダー">\s*<p class="museum-calendar__notice" role="status">[^<]*前回取得した予定[^<]*<\/p>\s*<pre class="override-month">/', $month) === 1
            && str_ends_with(rtrim($month), '</section>'), 'A theme month template must stay inside the plugin section, after the stale notice.');
        $check(!str_contains($month, '<table'), 'The theme month template did not replace the default one.');
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
            && str_ends_with(rtrim($englishMonth), '</section>'), 'A theme month template must stay inside the English section, after the stale notice.');
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
        $describedToday = 'description=[説明付きの催し] "午後の演奏会\n曲目：A\nB\nC\nD\n雨天決行 & 予約不要\n定員20名"';
        $themeToday = [
            (new Renderer(new Source($fixture), KindTable::defaults()))->renderToday($concertDay, 'n/j'),
            (new Renderer(new Source($fixture), KindTable::defaults(), '', Language::English))->renderToday($concertDay, 'D, M j'),
        ];
        $received($themeToday[0], 'lang=ja', $describedToday, 'url=[説明付きの催し] [https://example.com/concert]');
        $received($themeToday[1], 'lang=en', $describedToday, 'url=[説明付きの催し] [https://example.com/concert]');
        $meetingToday = (new Renderer(new Source($fixture), KindTable::defaults()))->renderToday($meetingDay, 'n/j');
        $received($meetingToday, 'description=[場所付きの催し] "受付で名前をお伝えください。\n持ち物：筆記用具, 上履き; 飲み物"');
        $themed = $month . $englishMonth . implode('', $themeToday) . $meetingToday;
        foreach (['&lt;script', '&lt;style', '&lt;b&gt;', '&lt;p&gt;', '&lt;br', '&lt;a ', '&lt;li', 'alert(', 'color: red'] as $markup) {
            $check(!str_contains($themed, $markup), "A theme template received markup of a description: {$markup}");
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
            'text=empty: No events today.',
            'date=Tue, Nov 10',
            'status=closed [CLOSED] []',
            'event=[休館日] [All day] all-day [] []',
            'label=[休館日] [CLOSED] []',
            'kind=[休館日] [closed]',
        );
        $received((new Renderer($facility, KindTable::defaults()))->renderToday($tuesdayNoon, 'n/j'), 'lang=ja', 'status=closed [休館日] []', 'event=[休館日] [終日] all-day [] []');
        $inJapanese(static function () use ($received, $check): string {
            $received(do_shortcode('[museum_calendar month="2026-11" lang="en"]'), 'lang=en', 'label=November 2026', 'weekdays=1Mon,2Tue,3Wed,4Thu,5Fri,6Sat,7Sun');
            $check(determine_locale() === 'ja', 'An English month must restore the locale of the site.');
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
        $check(substr_count($december, 'today=yes') === 1, 'Exactly one cell must be marked as today.');
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
            && str_ends_with(rtrim($today), '</section>'), 'A theme today template must stay inside the plugin section, after the stale notice.');
        $check(!str_contains($today, 'museum-calendar-today__date'), 'The theme today template did not replace the default one.');
        $received(
            $today,
            'variables=today,date,weekday,status,events,lang,text',
            'this=hidden',
            'scope=none',
            'lang=ja',
            'text=empty: 本日の予定はありません。',
            'today=' . $now->format('Y-m-d') . ' Asia/Tokyo',
            'date=' . $now->format('Y年n月j日'),
            'weekday=' . ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][(int) $now->format('N') - 1],
        );
        $received($inJapanese(static fn (): string => do_shortcode('[museum_calendar_today]')), 'weekday=' . ['月', '火', '水', '木', '金', '土', '日'][(int) $now->format('N') - 1]);
        $englishToday = $inJapanese(static fn (): string => do_shortcode('[museum_calendar_today lang="en"]'));
        $check(preg_match('/^<section class="museum-calendar-today" aria-label="Today&#039;s schedule" lang="en">\s*<p class="museum-calendar-today__notice" role="status">' . preg_quote($staleEnglish, '/') . '<\/p>\s*<pre class="override-today">/', $englishToday) === 1
            && str_ends_with(rtrim($englishToday), '</section>'), 'A theme today template must stay inside the English section, after the stale notice.');
        $received(
            $englishToday,
            'variables=today,date,weekday,status,events,lang,text',
            'lang=en',
            'text=empty: No events today.',
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
            'event=[一日休館] [終日] all-day [] []',
            'label=[一日休館] [一日休館] []',
            'kind=[一日休館] [other]',
            'event=[第2火曜講座] [13:00–15:00] timed [13:00] [15:00]',
        );
        foreach (['museum-fixture', 'calendar.google.com'] as $secret) {
            $check(!str_contains($month . $december . $today . $tuesday . $englishMonth . $englishToday, $secret), 'A theme template could read the calendar URL.');
        }

        add_filter('museum_calendar_fixture_throws', '__return_true');
        try {
            $level = ob_get_level();
            $html = do_shortcode('[museum_calendar month="2026-11"]') . do_shortcode('[museum_calendar_today]');
            $check(str_contains($html, 'この月の予定を表示できません') && str_contains($html, '本日の予定を表示できません'), 'A failing theme template must show the notice of its shortcode.');
            $check(!str_contains($html, 'output') && !str_contains($html, '<section') && ob_get_level() === $level, 'A failing theme template leaked partial output.');
            $inJapanese(static function () use ($check, $level, $tokyo): string {
                $html = do_shortcode('[museum_calendar month="2026-11" lang="en"]') . do_shortcode('[museum_calendar_today lang="en"]');
                $check($html === '<p class="museum-calendar-notice" role="status" lang="en">The schedule for this month cannot be shown.</p>'
                    . '<p class="museum-calendar-notice" role="status" lang="en">Today&#039;s schedule cannot be shown.</p>', 'A failing theme template must show the English notice of its shortcode and nothing else.');
                $check(determine_locale() === 'ja' && ob_get_level() === $level, 'A failing theme template in English must restore the locale of the site.');
                $check(wp_date('D F', (new DateTimeImmutable('2026-11-15 12:00', $tokyo))->getTimestamp(), $tokyo) === '日 11月', 'A failing theme template in English left English names behind.');
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
    $check(get_stylesheet() === $theme && str_contains(do_shortcode('[museum_calendar month="2026-11"]'), '<table'), 'The default template must be back after the theme is restored.');
    $plain = implode('', [
        (new Renderer(new Source($fixture), KindTable::defaults()))->render(new Month('2026-11'), $meetingDay),
        (new Renderer(new Source($fixture), KindTable::defaults(), '', Language::English))->render(new Month('2026-11'), $meetingDay),
        (new Renderer(new Source($fixture), KindTable::defaults()))->renderToday($meetingDay, 'n/j'),
        (new Renderer(new Source($fixture), KindTable::defaults(), '', Language::English))->renderToday($meetingDay, 'D, M j'),
        (new Renderer(new Source($fixture), KindTable::defaults()))->renderToday($concertDay, 'n/j'),
    ]);
    $check(substr_count($plain, '<li data-title="場所付きの催し"') === 4 && str_contains($plain, '<li data-title="説明付きの催し"'), 'The default templates did not print the events with a description.');
    $linked = '/<li data-title="説明付きの催し" data-kind="other"><a href="https:\/\/example\.com\/concert"><strong(?: lang="ja")?>説明付きの催し<\/strong><\/a><span>13:00–14:00<\/span><\/li>/';
    $check(preg_match_all($linked, $plain) === 3, 'The default templates must link the name of an event with a url, in both month calendars and in the list of today.');
    $check(preg_match_all('/<li [^>]*><a /', $plain) === 3, 'The default templates must not link the name of an event without a url.');
    $quoted = new Source(implode("\n", [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        "BEGIN:VEVENT\nUID:quoted\nDTSTART;TZID=Asia/Tokyo:20261126T130000\nDTEND;TZID=Asia/Tokyo:20261126T140000\nSUMMARY:説明付きの催し\nDESCRIPTION:https://example.com/?a=1&b=\"'>x\nEND:VEVENT",
        'END:VCALENDAR',
    ]));
    $escaped = (new Renderer($quoted, KindTable::defaults()))->render(new Month('2026-11'), $meetingDay) . (new Renderer($quoted, KindTable::defaults()))->renderToday($concertDay, 'n/j');
    $check(substr_count($escaped, '<a href="https://example.com/?a=1&#038;b=&#039;x"><strong>説明付きの催し</strong></a>') === 2 && !str_contains($escaped, '"\'>'), 'The default templates must escape the url of an event.');
    foreach (['受付で名前', '午後の演奏会', 'あいうえおかきくけこ', '定員20名'] as $description) {
        $check(!str_contains($plain, $description), "A default template printed a description: {$description}");
    }
    foreach (['非公開の会議室', 'location-7f3a', 'attendee-91c2', 'organizer-5d8e', 'attachment-3b6f'] as $private) {
        $check(!str_contains($plain . $themed, $private), "The location, an attendee, the organizer or an attachment reached a page: {$private}");
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
    $check($requests === $before, 'A cache stored before descriptions were shown must be used without fetching again.');
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
    $todayItems = static function (string $html): array {
        preg_match_all('/<li data-title="([^"]*)"/', $html, $titles);
        return $titles[1];
    };
    $closedDay = new DateTimeImmutable('2026-11-17 12:00', $tokyo);
    $html = (new Renderer($regular, KindTable::defaults()))->render(new Month('2026-11'), $closedDay);
    $check($cell($html, '2026-11-16') === ['開館日'] && $cell($html, '2026-11-18') === ['開館日'], 'A recurring 開館日 must show on the days without 休館日.');
    $check($cell($html, '2026-11-17') === ['休館日', 'ガイドツアー開催日'], 'A one-day 休館日 on top of a recurring 開館日 must hide that 開館日 in the month.');
    $html = (new Renderer($regular, KindTable::defaults()))->renderToday($closedDay, 'n/j');
    $check($statusOf($html) === 'closed 休館日 ' && $todayItems($html) === ['ガイドツアー開催日'], 'A one-day 休館日 on top of a recurring 開館日 must make today closed and list neither of them.');
    $check($statusOf((new Renderer($regular, KindTable::defaults()))->renderToday($closedDay->modify('+1 day'), 'n/j')) === 'open 開館日 10:30–16:30', 'A recurring 開館日 must make the other days open.');
    $html = (new Renderer($regular, KindTable::defaults(), '', Language::English))->renderToday($closedDay, 'n/j');
    $check($statusOf($html) === 'closed CLOSED ' && $statusOf((new Renderer($regular, KindTable::defaults(), '', Language::English))->renderToday($closedDay->modify('+1 day'), 'n/j')) === 'open OPEN 10:30–16:30', 'An English status of 休館日 and 開館日 must read CLOSED and OPEN.');

    $state = get_option(FeedRepository::CACHE);
    $state['fetched_at'] = time() - 400 * DAY_IN_SECONDS;
    update_option(FeedRepository::CACHE, $state, false);
    $html = do_shortcode('[museum_calendar month="2026-11"]');
    $check(str_contains($html, '終日展示') && str_contains($html, '前回取得した予定を表示しています') && !str_contains($html, '現在、予定を取得できません'), 'The last fetched events must stay visible, with the notice, however old they are.');
    $check(str_contains(do_shortcode('[museum_calendar_today]'), '前回取得した予定を表示しています'), 'Today must also keep using the last fetched events however old they are.');
    // 取得済みのデータが1つもなければ、表示できるものはない。
    $state['body'] = '';
    $state['fetched_at'] = 0;
    update_option(FeedRepository::CACHE, $state, false);
    $unavailable = '<p class="museum-calendar-notice" role="status"%s>%s</p>';
    foreach (['museum_calendar month="2026-11"', 'museum_calendar_today'] as $shortcode) {
        $check(do_shortcode("[{$shortcode}]") === sprintf($unavailable, '', '現在、予定を取得できません。時間をおいてご確認ください。'), "The notice of [{$shortcode}] without a usable feed changed.");
        $check(do_shortcode("[{$shortcode} lang=\"en\"]") === sprintf($unavailable, ' lang="en"', 'The schedule is currently unavailable. Please check again later.'), "[{$shortcode}] must report an unusable feed in English.");
    }

    $mode = 'ok';
    $feed->load(true);
    foreach (['<html>not a calendar</html>', str_repeat('x', Parser::MAX_BYTES + 1)] as $invalid) {
        $body = $invalid;
        $result = $feed->load(true);
        $check($result['body'] === $fixture && $result['stale'], 'Invalid response replaced valid cache.');
    }
    $body = "BEGIN:VCALENDAR\nVERSION:2.0\nEND:VCALENDAR";
    $result = $feed->load(true);
    $check($result['body'] === $body && !$result['stale'], 'Empty feed must remove deleted events.');
    $check(str_contains(do_shortcode('[museum_calendar month="2026-11"]'), 'この月の予定はありません'), 'Empty calendar message missing.');

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
    $check($feed->load(true)['stale'], 'The feed with 開館日 today did not become stale.');
    $html = do_shortcode('[museum_calendar_today lang="en"]');
    $check(str_contains($html, 'previously fetched schedule') && str_contains($html, '<p class="museum-calendar-today__status" data-kind="open" style="--museum-calendar-kind-color: #ffebee"><strong>OPEN</strong><span>10:30–16:30</span></p>'), 'A stale feed must show the status of the previously fetched events.');
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
        $check(count($section) === 2, 'The settings page must have one section for the titles without a kind.');
        foreach (['museum-fixture', 'calendar.google.com', 'Secret URL', 'feed_unavailable'] as $secret) {
            $check(!str_contains($section[1], $secret), 'The list of titles printed the calendar URL or the cause of a failed fetch.');
        }
        preg_match_all('/<li>(.*?)<\/li>/s', $section[1], $items);
        return ['titles' => $items[1], 'complete' => false, 'unreadable' => str_contains($section[1], '予定を読み込めないため')];
    };
    $check($untyped($settingsPage()) === ['titles' => [], 'complete' => true, 'unreadable' => false], 'A feed without events must read as a feed whose titles all have a kind.');
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
    $check($untyped($adminHtml) === ['titles' => ['臨時休館', '&lt;b&gt;特別展&lt;/b&gt;'], 'complete' => false, 'unreadable' => false], 'The settings page must list, once and escaped, each title of the next 12 months without a kind, and no other title.');
    $check(!str_contains($adminHtml, '<b>特別展'), 'The list of titles printed markup of a title.');
    $kindsForm = explode('<h2 id="museum-calendar-kinds">予定の種類と英語での表示</h2>', $adminHtml);
    $check(count($kindsForm) === 2 && str_contains($kindsForm[1], 'name="action" value="museum_calendar_save_kinds"') && str_contains($kindsForm[1], 'name="_wpnonce"'), 'The settings page must have its own form for the kind table.');
    $kindsForm = explode('</form>', $kindsForm[1])[0];
    $check(substr_count($kindsForm, '<input type="text" name="kinds[') === (count(KindTable::DEFAULTS) + 3) * 4, 'The kind form must show each default row and three blank rows.');
    $check(str_contains($kindsForm, '<input type="color" value="#ffebee" aria-label="1行目の色を選ぶ"><input type="text" name="kinds[0][color]" value="#ffebee" aria-label="1行目の色" class="widefat code" placeholder="#e3f2fd" spellcheck="false">'), 'The color of a row must have a picker without a name before its text field.');
    $check(str_contains($kindsForm, '<input type="color" value="#ffffff" aria-label="9行目の色を選ぶ" data-empty><input type="text" name="kinds[8][color]" value=""') && substr_count($kindsForm, '<input type="color" ') === count(KindTable::DEFAULTS) + 3 && substr_count($kindsForm, ' data-empty>') === 3, 'A row without a color must have an empty text field and a picker marked as empty.');
    $check(substr_count($kindsForm, '<script>') === 1 && str_contains($kindsForm, "addEventListener('input'"), 'The kind form must keep the pickers and the text fields in step with one script.');
    $check(str_contains($kindsForm, 'name="kinds[0][title]" value="開館日" aria-label="1行目のタイトル"') && str_contains($kindsForm, 'name="kinds[7][english]" value="Event" aria-label="8行目の英語での表示"') && str_contains($kindsForm, 'name="kinds[10][kind]" value="" aria-label="11行目の種類"'), 'Each input of the kind form must have its value and an accessible name.');
    $check(str_contains($kindsForm, 'name="reset" value="1"> 既定の表に戻す</label>'), 'The kind form must offer a reset to the default table.');
    foreach (['museum-fixture', 'calendar.google.com'] as $secret) {
        $check(!str_contains($kindsForm, $secret), 'The kind form printed the calendar.');
    }
    $calendarId = get_option(Settings::OPTION);
    $cache = get_option(FeedRepository::CACHE);
    $closedRow = ['title' => '臨時休館', 'kind' => 'closed', 'english' => 'Closed', 'color' => '#e26b6b'];
    $check(Settings::saveKinds(['kinds' => [...KindTable::DEFAULTS, $closedRow]]) === true, 'A kind table with a second closed title was rejected.');
    $check(get_option(Settings::OPTION) === $calendarId && get_option(FeedRepository::CACHE) === $cache, 'Saving the kind table changed the calendar or its events.');
    $adminHtml = $settingsPage();
    $check($untyped($adminHtml)['titles'] === ['&lt;b&gt;特別展&lt;/b&gt;'], 'A title added to the kind table must leave the list of titles without a kind.');
    $check(str_contains($adminHtml, 'name="kinds[8][title]" value="臨時休館"') && substr_count($adminHtml, '<input type="text" name="kinds[') === (count(KindTable::DEFAULTS) + 4) * 4, 'The kind form must show the saved rows.');
    $check(str_contains($adminHtml, '<input type="color" value="#e26b6b" aria-label="9行目の色を選ぶ"><input type="text" name="kinds[8][color]" value="#e26b6b"') && substr_count($adminHtml, ' data-empty>') === 3, 'The kind form must show a saved color in its picker, and mark only the blank rows as empty.');
    $check(Settings::save(['url' => $url]) === true && get_option(Settings::KINDS_OPTION) === [...KindTable::DEFAULTS, $closedRow], 'Saving the calendar URL changed the kind table.');
    $notice = static function (string $result) use ($settingsPage): string {
        $_GET['result'] = $result;
        try {
            return $settingsPage();
        } finally {
            unset($_GET['result']);
        }
    };
    $heading = '<h2 id="museum-calendar-kinds">予定の種類と英語での表示</h2>';
    $check(preg_match('/' . preg_quote($heading, '/') . '\s*<div class="notice notice-success inline"><p>予定の種類を保存しました。<\/p><\/div>/u', $notice('kinds_saved')) === 1, 'A saved kind table must be reported under the heading of the kind form.');
    foreach (['kinds_duplicate_title' => '同じタイトルの行が複数あります', 'kinds_invalid_kind' => 'other は使えません', 'kinds_too_long' => '200文字以内', 'kinds_too_many' => '100行まで', 'kinds_invalid_title' => '改行や制御文字', 'kinds_invalid_rows' => '入力内容を確認してください', 'kinds_forbidden' => null, 'kinds_<b>x</b>' => null] as $result => $text) {
        $html = $notice($result);
        $check($text === null
            ? !str_contains($html, 'notice-error') && !str_contains($html, '<b>x')
            : preg_match('/' . preg_quote($heading, '/') . '\s*<div class="notice notice-error inline"><p>予定の種類を保存できませんでした。[^<]*' . preg_quote($text, '/') . '/u', $html) === 1 && substr_count($html, 'notice-error') === 1, "The notice of {$result} is wrong.");
    }
    $check(str_contains($notice('saved'), '<div class="notice notice-success"><p>設定を保存しました。</p></div>') && str_contains($notice('invalid'), '<div class="notice notice-error"><p>URLを保存できませんでした。'), 'The notices of the URL form must stay.');
    $check(!str_contains($notice('saved'), '予定の種類を保存しました') && !str_contains($notice('kinds_saved'), '設定を保存しました。'), 'The notices of the two forms must stay apart.');
    $check(Settings::saveKinds(['reset' => '1']) === true && get_option(Settings::KINDS_OPTION, null) === null, 'The kind table was not reset.');
    $body = $calendar($typed);
    $feed->load(true);
    $check($untyped($settingsPage()) === ['titles' => [], 'complete' => true, 'unreadable' => false], 'The settings page must leave the section out when every title has a kind.');
    $mode = 'timeout';
    $state = get_option(FeedRepository::CACHE);
    $state['body'] = '';
    $state['fetched_at'] = 0;
    $state['attempted_at'] = 0;
    update_option(FeedRepository::CACHE, $state, false);
    $before = $requests;
    $adminHtml = $settingsPage();
    $check($requests === $before + 1 && $untyped($adminHtml) === ['titles' => [], 'complete' => false, 'unreadable' => true], 'A feed that cannot be read must be reported in the section.');
    foreach (['Secret URL', 'feed_unavailable'] as $secret) {
        $check(!str_contains($adminHtml, $secret), 'The settings page printed the cause of a failed fetch.');
    }
    $check(preg_match('#<p role="alert">取得失敗：\d{4}/\d{2}/\d{2} \d{2}:\d{2}　更新に失敗しました。#', $adminHtml) === 1 && !str_contains($adminHtml, 'まだ取得していません'), 'A failed fetch must be shown with its time and the generic error.');
    $mode = 'ok';

    Settings::save(['url' => 'https://calendar.google.com/calendar/ical/museum-fixture-another%40group.calendar.google.com/public/basic.ics']);
    $check(get_option(FeedRepository::CACHE, null) === null, 'Changed source retained previous source data.');
    $mode = 'http_error';
    $check(str_contains(do_shortcode('[museum_calendar]'), '現在、予定を取得できません'), 'Initial HTTP failure was not handled.');
    $check(str_contains(do_shortcode('[museum_calendar_today]'), '現在、予定を取得できません'), 'Today shortcode did not handle the HTTP failure.');
    foreach (['', ' lang="en"'] as $lang) {
        $html = do_shortcode("[museum_calendar_today{$lang}]");
        $check(preg_match('/^<p class="museum-calendar-notice" role="status"[^>]*>[^<]*<\/p>$/', $html) === 1 && preg_match('/休館|開館|CLOSE|OPEN|__status/', $html) === 0, 'Today without a usable feed must show only its notice, with no status and no word for closed.');
    }
    $mode = 'ok';
    $body = $fixture;
    $feed->load(true);
    $state = get_option(FeedRepository::CACHE);
    $state['attempted_at'] = time() - 901;
    update_option(FeedRepository::CACHE, $state, false);
    $before = $requests;
    $feed->load();
    $check($requests === $before + 1, 'Expired cache was not refreshed.');
    $state = get_option(FeedRepository::CACHE);
    $state['body'] = 'not a calendar';
    update_option(FeedRepository::CACHE, $state, false);
    $html = do_shortcode('[museum_calendar_today]');
    $check(str_contains($html, '本日の予定を表示できません') && !str_contains($html, 'museum-fixture'), 'Unreadable feed was not handled by the today shortcode.');

    wp_set_current_user(0);
    $check(is_wp_error(Settings::save(['disconnect' => '1'])), 'Unauthorized settings change was allowed.');
    $check(Settings::calendar() !== null, 'Unauthorized request changed configuration.');
    wp_set_current_user(1);
    $before = $requests;
    $check(Settings::saveAndRefresh(['url' => $privateUrl]) === true && Settings::calendar()?->secret === $token && get_option(FeedRepository::CACHE, null) !== null && $requests === $before + 1, 'The private URL was not connected before the disconnect.');
    $check(Settings::saveAndRefresh(['url' => $privateUrl, 'disconnect' => '1']) === true && $requests === $before + 1, 'Disconnecting must not fetch, whatever the field holds.');
    $check(get_option(Settings::OPTION, null) === null && get_option(Settings::SECRET_OPTION, null) === null && get_option(FeedRepository::CACHE, null) === null, 'Disconnecting must delete the calendar ID, the secret and the events.');
    $check(Settings::saveAndRefresh(['url' => '']) === true && Settings::calendar() === null && $requests === $before + 1, 'An empty field without a calendar must change nothing and fetch nothing.');
    $adminHtml = $settingsPage();
    $check(!str_contains($adminHtml, '種類が未定義の予定名'), 'Without a calendar the settings page must not list titles.');
    $check(str_contains($adminHtml, '<p id="museum-calendar-connection">登録状況：未登録</p>') && !str_contains($adminHtml, 'name="disconnect"'), 'Without a calendar the settings page must say so, and offer no disconnect.');

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
WP_CLI::success("ICS integration: {$checks} checks passed.");
