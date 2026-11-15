<?php
use MuseumCalendar\Admin\Settings;
use MuseumCalendar\Calendar\Month;
use MuseumCalendar\Frontend\Renderer;
use MuseumCalendar\Ics\FeedRepository;
use MuseumCalendar\Ics\Parser;
use MuseumCalendar\Ics\Source;

if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'development') {
    exit(1);
}
$saved = [];
foreach ([Settings::OPTION, FeedRepository::CACHE, FeedRepository::LOCK, 'museum_calendar_demo'] as $key) {
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
    if (!str_contains($url, '/museum-fixture/')) {
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
$url = 'https://calendar.google.com/museum-fixture/private-secret/basic.ics';
$feed = new FeedRepository();
try {
    foreach (['http://calendar.google.com/a.ics', 'https://127.0.0.1/a.ics', 'https://localhost/a.ics', 'https://169.254.169.254/a.ics', 'https://user:password@calendar.google.com/a.ics', 'https://calendar.google.com:8443/a.ics', 'file:///etc/passwd'] as $badUrl) {
        $check(is_wp_error(Settings::save(['url' => $badUrl])), 'Unsafe URL was accepted.');
    }
    $check(Settings::save(['url' => $url]) === true, 'Valid HTTPS URL was rejected.');
    $check(Settings::save(['url' => '']) === true && Settings::url() === $url, 'Empty field must keep the secret.');
    $check(!get_option('museum_calendar_demo'), 'ICS settings must disable demo data.');
    $first = $feed->load();
    $check($first['body'] === $fixture && !$first['stale'], 'First fetch failed.');
    $feed->load();
    $check($requests === 1, 'Fresh cache made another HTTP request.');
    $html = do_shortcode('[museum_calendar month="2026-11"]');
    $check(str_contains($html, '日時変更ガイド') && str_contains($html, '終日展示'), 'ICS events were not rendered.');
    $check(!str_contains($html, '動作確認用サンプル') && !str_contains($html, 'private-secret'), 'Demo label or secret leaked.');

    ob_start();
    Settings::page();
    $adminHtml = ob_get_clean();
    $check(!str_contains($adminHtml, 'private-secret'), 'Stored URL leaked into settings HTML.');
    $check(str_contains($adminHtml, '_wpnonce') && str_contains($adminHtml, '最終成功'), 'Admin form/status missing.');

    $mode = 'timeout';
    $stale = $feed->load(true);
    $check($stale['stale'] && $stale['body'] === $fixture, 'Failed fetch discarded last good data.');
    $check(!str_contains($stale['error'], $url), 'Error exposed a secret URL.');
    $html = do_shortcode('[museum_calendar month="2026-11"]');
    $check(str_contains($html, '前回取得した予定'), 'Stale data was shown without a notice.');
    $before = $requests;
    $feed->load();
    $check($requests === $before, 'Failure retry backoff was ignored.');

    $tokyo = new DateTimeZone('Asia/Tokyo');
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
            'variables=month,label,weekdays,weeks,previous,next,empty',
            'this=hidden',
            'scope=none',
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
            'event=2026-11-17 [日時変更ガイド] [14:00–15:00] timed [14:00] [15:00] [note for 日時変更ガイド]',
            'event=2026-11-30 [月末夜間] [23:00–24:00] timed [23:00] [24:00] [note for 月末夜間]',
        );
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
            'variables=today,date,weekday,events',
            'this=hidden',
            'scope=none',
            'today=' . $now->format('Y-m-d') . ' Asia/Tokyo',
            'date=' . $now->format('Y年n月j日'),
            'weekday=' . ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][(int) $now->format('N') - 1],
        );
        $received($inJapanese(static fn (): string => do_shortcode('[museum_calendar_today]')), 'weekday=' . ['月', '火', '水', '木', '金', '土', '日'][(int) $now->format('N') - 1]);
        $renderTuesday = static fn (): string => (new Renderer(new Source($fixture)))->renderToday(new DateTimeImmutable('2026-11-09 15:30', new DateTimeZone('UTC')), 'n/j H:i');
        $received($inJapanese($renderTuesday), 'weekday=火');
        $tuesday = $renderTuesday();
        $received(
            $tuesday,
            'today=2026-11-10 Asia/Tokyo',
            'date=11/10 00:30',
            'weekday=Tue',
            'event=[一日休館] [終日] all-day [] [] [note for 一日休館]',
            'event=[第2火曜講座] [13:00–15:00] timed [13:00] [15:00] [note for 第2火曜講座]',
        );
        foreach (['private-secret', 'museum-fixture', 'calendar.google.com'] as $secret) {
            $check(!str_contains($month . $december . $today . $tuesday, $secret), 'A theme template could read the ICS URL.');
        }

        add_filter('museum_calendar_fixture_throws', '__return_true');
        try {
            $level = ob_get_level();
            $html = do_shortcode('[museum_calendar month="2026-11"]') . do_shortcode('[museum_calendar_today]');
            $check(str_contains($html, 'この月の予定を表示できません') && str_contains($html, '本日の予定を表示できません'), 'A failing theme template must show the notice of its shortcode.');
            $check(!str_contains($html, 'output') && !str_contains($html, '<section') && ob_get_level() === $level, 'A failing theme template leaked partial output.');
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

    Settings::save(['url' => 'https://calendar.google.com/museum-fixture/another-secret/basic.ics']);
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
    $check(str_contains($html, '本日の予定を表示できません') && !str_contains($html, 'another-secret'), 'Unreadable feed was not handled by the today shortcode.');

    wp_set_current_user(0);
    $check(is_wp_error(Settings::save(['disconnect' => '1'])), 'Unauthorized settings change was allowed.');
    $check(Settings::url() !== '', 'Unauthorized request changed configuration.');
    wp_set_current_user(1);
    Settings::save(['disconnect' => '1']);
    $check(Settings::url() === '' && get_option(FeedRepository::CACHE, null) === null, 'Disconnect did not clear the source and feed.');
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
