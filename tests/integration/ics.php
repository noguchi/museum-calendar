<?php
use MuseumCalendar\Admin\Settings;
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
foreach ([Settings::OPTION, Settings::LEGACY_OPTION, FeedRepository::CACHE, FeedRepository::LOCK, 'museum_calendar_demo'] as $key) {
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
$mock = static function ($pre, $args, $url) use (&$body, &$mode, &$requests, $check) {
    if (!str_contains($url, 'museum-fixture')) {
        return $pre;
    }
    $requests++;
    $check($args['reject_unsafe_urls'] === true && $args['redirection'] === 0, 'Unsafe HTTP options.');
    $check($args['limit_response_size'] === Parser::MAX_BYTES + 1, 'Missing response size limit.');
    if ($mode === 'timeout') {
        return new WP_Error('timeout', 'Secret URL must never leak: ' . $url);
    }
    return ['response' => ['code' => $mode === 'http_error' ? 403 : 200], 'body' => $body, 'headers' => []];
};
add_filter('pre_http_request', $mock, 10, 3);
$url = 'https://calendar.google.com/calendar/embed?src=museum-fixture%40group.calendar.google.com';
$feed = new FeedRepository();
try {
    $check(has_action('init', [Settings::class, 'migrate']) === 0, 'The update from 0.7.0 must run before the calendar is read.');
    foreach (['https://calendar.google.com/calendar/ical/museum-fixture%40group.calendar.google.com/private-secret/basic.ics', 'http://calendar.google.com/calendar/embed?src=museum-fixture%40group.calendar.google.com', 'https://127.0.0.1/calendar/embed?src=museum-fixture%40group.calendar.google.com'] as $badUrl) {
        $check(is_wp_error(Settings::save(['url' => $badUrl])), 'A URL other than a public Google Calendar URL was accepted.');
    }
    $check(Settings::calendar() === null && $requests === 0, 'A rejected URL was saved or fetched.');
    $check(Settings::save(['url' => $url]) === true && Settings::calendar()?->publicUrl() === $url, 'The public URL was rejected.');
    $check(!get_option('museum_calendar_demo'), 'A calendar must disable demo data.');
    $first = $feed->load();
    $check($first['body'] === $fixture && !$first['stale'], 'First fetch failed.');
    $feed->load();
    $check($requests === 1, 'Fresh cache made another HTTP request.');
    $check(Settings::save(['url' => Settings::calendar()->feedUrl()]) === true && get_option(FeedRepository::CACHE, null) !== null, 'The iCal address of the same calendar discarded its events.');
    $html = do_shortcode('[museum_calendar month="2026-11"]');
    $check(str_contains($html, '日時変更ガイド') && str_contains($html, '終日展示'), 'ICS events were not rendered.');
    $check(!str_contains($html, '動作確認用サンプル') && !str_contains($html, 'museum-fixture'), 'Demo label or the calendar URL reached the page.');

    ob_start();
    Settings::page();
    $adminHtml = ob_get_clean();
    $check(str_contains($adminHtml, '<input type="text" id="museum-calendar-url" name="url" value="' . esc_attr($url) . '"'), 'The settings field must show the public URL as text.');
    $check(str_contains($adminHtml, '_wpnonce') && str_contains($adminHtml, '最終成功'), 'Admin form/status missing.');

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
    $note = static function (array $events): array {
        foreach ($events as $date => $day) {
            foreach ($day as $index => $event) {
                $events[$date][$index]['fixture_note'] = 'note for ' . $event['title'];
                $events[$date][$index]['kind'] = 'kind of a filter';
            }
        }
        return $events;
    };
    $check(wp_get_theme('override-theme')->exists(), 'The fixture theme is missing from the test environment.');
    $theme = get_stylesheet();
    $startOfWeek = get_option('start_of_week');
    $dateFormat = get_option('date_format');
    add_filter('museum_calendar_events', $note);
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
            'event=2026-11-10 [一日休館] [終日] all-day [] [] [note for 一日休館]',
            'label=2026-11-10 [一日休館] [一日休館] []',
            'kind=2026-11-10 [一日休館] [other]',
            'event=2026-11-17 [日時変更ガイド] [14:00–15:00] timed [14:00] [15:00] [note for 日時変更ガイド]',
            'event=2026-11-30 [月末夜間] [23:00–24:00] timed [23:00] [24:00] [note for 月末夜間]',
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
            'event=2026-11-10 [一日休館] [All day] all-day [] [] [note for 一日休館]',
            'label=2026-11-10 [一日休館] [一日休館] [ja]',
            'event=2026-11-17 [日時変更ガイド] [14:00–15:00] timed [14:00] [15:00] [note for 日時変更ガイド]',
            'label=2026-11-17 [日時変更ガイド] [日時変更ガイド] [ja]',
        );
        $facility = new Source(implode("\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            "BEGIN:VEVENT\nUID:close\nDTSTART;VALUE=DATE:20261110\nSUMMARY:CLOSE\nEND:VEVENT",
            "BEGIN:VEVENT\nUID:tea\nDTSTART;TZID=Asia/Tokyo:20261111T120000\nDTEND;TZID=Asia/Tokyo:20261111T160000\nSUMMARY:お茶室営業日\nEND:VEVENT",
            "BEGIN:VEVENT\nUID:untitled\nDTSTART;VALUE=DATE:20261112\nEND:VEVENT",
            'END:VCALENDAR',
        ]));
        $tuesdayNoon = new DateTimeImmutable('2026-11-10 12:00', $tokyo);
        $received(
            (new Renderer($facility, false, '', Language::English))->render(new Month('2026-11'), $tuesdayNoon),
            'lang=en',
            'event=2026-11-10 [CLOSE] [All day] all-day [] [] [note for CLOSE]',
            'label=2026-11-10 [CLOSE] [CLOSED] []',
            'kind=2026-11-10 [CLOSE] [closed]',
            'event=2026-11-11 [お茶室営業日] [12:00–16:00] timed [12:00] [16:00] [note for お茶室営業日]',
            'label=2026-11-11 [お茶室営業日] [Tea Room Open] []',
            'kind=2026-11-11 [お茶室営業日] [tea]',
            'label=2026-11-12 [（無題）] [(Untitled)] []',
            'kind=2026-11-12 [（無題）] [other]',
        );
        $received(
            (new Renderer($facility))->render(new Month('2026-11'), $tuesdayNoon),
            'lang=ja',
            'event=2026-11-10 [CLOSE] [終日] all-day [] [] [note for CLOSE]',
            'label=2026-11-10 [CLOSE] [CLOSE] []',
            'kind=2026-11-10 [CLOSE] [closed]',
            'label=2026-11-11 [お茶室営業日] [お茶室営業日] []',
            'kind=2026-11-11 [お茶室営業日] [tea]',
            'label=2026-11-12 [（無題）] [（無題）] []',
            'kind=2026-11-12 [（無題）] [other]',
        );
        $received(
            (new Renderer($facility, false, '', Language::English))->renderToday($tuesdayNoon, 'D, M j'),
            'lang=en',
            'text=empty: No events today.',
            'date=Tue, Nov 10',
            'event=[CLOSE] [All day] all-day [] [] [note for CLOSE]',
            'label=[CLOSE] [CLOSED] []',
            'kind=[CLOSE] [closed]',
        );
        $inJapanese(static function () use ($received, $check): string {
            $received(do_shortcode('[museum_calendar month="2026-11" lang="en"]'), 'lang=en', 'label=November 2026', 'weekdays=1Mon,2Tue,3Wed,4Thu,5Fri,6Sat,7Sun');
            $check(determine_locale() === 'ja', 'An English month must restore the locale of the site.');
            $received(do_shortcode('[museum_calendar month="2026-11"]'), 'lang=ja', 'label=11月 2026', 'weekdays=1月,2火,3水,4木,5金,6土,7日');
            return '';
        });
        $december = (new Renderer(new Source($fixture)))->render(new Month('2026-12'), new DateTimeImmutable('2026-11-30 15:30', new DateTimeZone('UTC')));
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
        $japanese = $inJapanese(static fn (): string => (new Renderer(new Source($fixture)))->render(new Month('2026-12'), $now));
        $received($japanese, 'label=12月 2026', 'weekdays=1月,2火,3水,4木,5金,6土,7日');
        $linked($japanese, ['previous' => ['2026-11', '11月'], 'next' => ['2027-01', '1月']]);
        $check(substr_count($december, 'today=yes') === 1, 'Exactly one cell must be marked as today.');
        $received((new Renderer(new Source("BEGIN:VCALENDAR\nVERSION:2.0\nEND:VCALENDAR")))->render(new Month('2026-11'), $now), 'empty=yes');

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
            'event=2026-11-10 [一日休館] [終日] all-day [] [] [note for 一日休館]',
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
            'variables=today,date,weekday,events,lang,text',
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
            'variables=today,date,weekday,events,lang,text',
            'lang=en',
            'text=empty: No events today.',
            'today=' . $now->format('Y-m-d') . ' Asia/Tokyo',
            'date=' . $now->format('D, M j'),
            'weekday=' . $now->format('D'),
        );
        $renderTuesday = static fn (): string => (new Renderer(new Source($fixture)))->renderToday(new DateTimeImmutable('2026-11-09 15:30', new DateTimeZone('UTC')), 'n/j H:i');
        $received($inJapanese($renderTuesday), 'weekday=火');
        $tuesday = $renderTuesday();
        $received(
            $tuesday,
            'today=2026-11-10 Asia/Tokyo',
            'date=11/10 00:30',
            'weekday=Tue',
            'event=[一日休館] [終日] all-day [] [] [note for 一日休館]',
            'label=[一日休館] [一日休館] []',
            'kind=[一日休館] [other]',
            'event=[第2火曜講座] [13:00–15:00] timed [13:00] [15:00] [note for 第2火曜講座]',
        );
        foreach (['museum-fixture', 'calendar.google.com'] as $secret) {
            $check(!str_contains($month . $december . $today . $tuesday . $englishMonth . $englishToday, $secret), 'A theme template could read the calendar URL.');
        }
        $check(!str_contains($month . $december . $tuesday . $englishMonth, 'kind of a filter'), 'A theme template received the kind that a filter set.');

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
        remove_filter('museum_calendar_events', $note);
    }
    $check(get_stylesheet() === $theme && str_contains(do_shortcode('[museum_calendar month="2026-11"]'), '<table'), 'The default template must be back after the theme is restored.');

    $state = get_option(FeedRepository::CACHE);
    $state['fetched_at'] = time() - FeedRepository::MAX_AGE - 1;
    update_option(FeedRepository::CACHE, $state, false);
    $html = do_shortcode('[museum_calendar month="2026-11"]');
    $check(str_contains($html, '現在、予定を取得できません') && !str_contains($html, '終日展示'), 'Expired stale data remained visible.');
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

    $settingsPage = static function (): string {
        ob_start();
        Settings::page();
        return ob_get_clean();
    };
    $untranslated = static function (string $html) use ($check): array {
        $section = explode('<h2>英訳のない予定名</h2>', $html);
        $check(count($section) === 2, 'The settings page must have one section for the titles without an English text.');
        foreach (['museum-fixture', 'calendar.google.com', 'Secret URL', 'feed_unavailable'] as $secret) {
            $check(!str_contains($section[1], $secret), 'The list of titles printed the calendar URL or the cause of a failed fetch.');
        }
        $check(str_contains($section[1], '<code>lang="en"</code>') && str_contains($section[1], '日本語のまま表示'), 'The section must say what happens to its titles on an English page.');
        preg_match_all('/<li>(.*?)<\/li>/s', $section[1], $items);
        return ['titles' => $items[1], 'complete' => str_contains($section[1], 'すべての予定名に英訳があります。'), 'unreadable' => str_contains($section[1], '予定を読み込めないため')];
    };
    $check($untranslated($settingsPage()) === ['titles' => [], 'complete' => true, 'unreadable' => false], 'A feed without events must read as a feed whose titles all have an English text.');
    $firstDay = new DateTimeImmutable($now->format('Y-m-01'), $tokyo);
    $allDayEvent = static fn (string $uid, DateTimeImmutable $day, string $summary = ''): string
        => "BEGIN:VEVENT\nUID:{$uid}\nDTSTART;VALUE=DATE:" . $day->format('Ymd') . ($summary === '' ? '' : "\nSUMMARY:{$summary}") . "\nEND:VEVENT";
    $calendar = static fn (array $events): string => implode("\n", ['BEGIN:VCALENDAR', 'VERSION:2.0', ...$events, 'END:VCALENDAR']);
    $translated = [
        $allDayEvent('open', $firstDay, 'OPEN'),
        $allDayEvent('close', $firstDay->modify('+1 day'), 'CLOSE'),
        $allDayEvent('tea', $firstDay->modify('+2 days'), 'お茶室営業日'),
        $allDayEvent('tour', $firstDay->modify('+3 months'), 'ガイドツアー開催日'),
        $allDayEvent('wedding', $firstDay->modify('+6 months'), '結婚式'),
        $allDayEvent('evening', $firstDay->modify('+11 months'), '夜間特別開館'),
        $allDayEvent('untitled', $firstDay->modify('+4 days')),
    ];
    $body = $calendar([
        ...$translated,
        $allDayEvent('before', $firstDay->modify('-1 day'), '先月の催し'),
        $allDayEvent('extra', $firstDay->modify('+5 days'), '臨時休館'),
        $allDayEvent('spaced', $firstDay->modify('+6 days'), '  臨時休館 '),
        $allDayEvent('again', $firstDay->modify('+2 months'), '臨時休館'),
        $allDayEvent('markup', $firstDay->modify('+11 months +20 days'), '<b>特別展</b>'),
        $allDayEvent('after', $firstDay->modify('+12 months'), '来年の催し'),
    ]);
    $feed->load(true);
    $adminHtml = $settingsPage();
    $check($untranslated($adminHtml) === ['titles' => ['臨時休館', '&lt;b&gt;特別展&lt;/b&gt;'], 'complete' => false, 'unreadable' => false], 'The settings page must list, once and escaped, each title of the next 12 months without an English text, and no other title.');
    $check(!str_contains($adminHtml, '<b>特別展'), 'The list of titles printed markup of a title.');
    $rename = static function (array $events): array {
        foreach ($events as $date => $day) {
            foreach ($day as $index => $event) {
                $events[$date][$index]['title'] = ['臨時休館' => 'CLOSE', 'OPEN' => '開館日'][trim($event['title'])] ?? $event['title'];
            }
        }
        return $events;
    };
    add_filter('museum_calendar_events', $rename);
    try {
        $check($untranslated($settingsPage())['titles'] === ['開館日', '&lt;b&gt;特別展&lt;/b&gt;'], 'The list must hold the titles that the site displays, after the museum_calendar_events filter.');
    } finally {
        remove_filter('museum_calendar_events', $rename);
    }
    add_filter('museum_calendar_events', '__return_null');
    try {
        $check($untranslated($settingsPage()) === ['titles' => [], 'complete' => false, 'unreadable' => true], 'Events that cannot be displayed must not read as titles that all have an English text.');
    } finally {
        remove_filter('museum_calendar_events', '__return_null');
    }
    $body = $calendar($translated);
    $feed->load(true);
    $check($untranslated($settingsPage()) === ['titles' => [], 'complete' => true, 'unreadable' => false], 'The settings page must say that every title has an English text.');
    $mode = 'timeout';
    $state = get_option(FeedRepository::CACHE);
    $state['fetched_at'] = time() - FeedRepository::MAX_AGE - 1;
    $state['attempted_at'] = 0;
    update_option(FeedRepository::CACHE, $state, false);
    $before = $requests;
    $adminHtml = $settingsPage();
    $check($requests === $before + 1 && $untranslated($adminHtml) === ['titles' => [], 'complete' => false, 'unreadable' => true], 'A feed that cannot be read must be reported in the section.');
    foreach (['Secret URL', 'feed_unavailable'] as $secret) {
        $check(!str_contains($adminHtml, $secret), 'The settings page printed the cause of a failed fetch.');
    }
    $mode = 'ok';

    Settings::save(['url' => 'https://calendar.google.com/calendar/embed?src=museum-fixture-another%40group.calendar.google.com']);
    $check(get_option(FeedRepository::CACHE, null) === null, 'Changed source retained previous source data.');
    $mode = 'http_error';
    $check(str_contains(do_shortcode('[museum_calendar]'), '現在、予定を取得できません'), 'Initial HTTP failure was not handled.');
    $check(str_contains(do_shortcode('[museum_calendar_today]'), '現在、予定を取得できません'), 'Today shortcode did not handle the HTTP failure.');
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
    $check(is_wp_error(Settings::save(['url' => ''])), 'Unauthorized settings change was allowed.');
    $check(Settings::calendar() !== null, 'Unauthorized request changed configuration.');
    wp_set_current_user(1);
    Settings::save(['url' => '']);
    $check(Settings::calendar() === null && get_option(FeedRepository::CACHE, null) === null, 'An empty URL did not clear the calendar and feed.');
    $check(!str_contains($settingsPage(), '英訳のない予定名'), 'Without a calendar the settings page must not list titles.');

    $migrated = static function (string $legacy) use ($check): ?string {
        update_option(Settings::LEGACY_OPTION, $legacy, false);
        update_option(FeedRepository::CACHE, ['body' => 'events of the secret feed'], false);
        Settings::migrate();
        $check(get_option(Settings::LEGACY_OPTION, null) === null && get_option(FeedRepository::CACHE, null) === null, 'The update kept the 0.7.0 URL or its events.');
        return Settings::calendar()?->id;
    };
    $id = 'museum-fixture@group.calendar.google.com';
    $check($migrated('https://calendar.google.com/calendar/ical/museum-fixture%40group.calendar.google.com/private-0123abcd/basic.ics') === $id, 'The update lost the calendar of a secret iCal URL.');
    Settings::migrate();
    $check(Settings::calendar()?->id === $id, 'Running the update again changed the calendar.');
    $another = 'museum-fixture-another@group.calendar.google.com';
    $check($migrated('https://calendar.google.com/calendar/ical/museum-fixture-another%40group.calendar.google.com/public/basic.ics') === $another, 'Updating again after going back to 0.7.0 kept the older calendar.');
    Settings::save(['url' => '']);
    $check($migrated('https://www.google.com/calendar/ical/museum-fixture%40group.calendar.google.com/public/basic.ics') === $id, 'The update lost the calendar of an older public iCal URL.');
    Settings::save(['url' => '']);
    $check($migrated('https://calendar.google.com/calendar/ical/museum-fixture%40group.calendar.google.com/public/basic.ics') === $id, 'The update lost the calendar of a public iCal URL.');
    Settings::save(['url' => '']);
    $check($migrated('https://example.com/museum.ics') === null, 'The update made a calendar from a URL that is not Google Calendar.');
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
