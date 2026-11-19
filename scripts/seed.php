<?php
/** Idempotent local fixture setup, executed through WP-CLI. */
if (!defined('WP_CLI') || !WP_CLI) {
    exit(1);
}
if (!in_array(wp_get_environment_type(), ['local', 'development'], true)) {
    WP_CLI::error('サンプルデータは開発環境にのみ作成できます。');
}
update_option('timezone_string', 'Asia/Tokyo');
if (defined('MUSEUM_CALENDAR_TEST_FIXTURES') && MUSEUM_CALENDAR_TEST_FIXTURES) {
    // The browser tests expect Monday first. The development site keeps the week start its administrator chose.
    update_option('start_of_week', 1);
    update_option(\MuseumCalendar\Admin\Settings::OPTION, 'museum-fixture@group.calendar.google.com', false);
    delete_option('museum_calendar_feed');
    update_option('museum_calendar_demo', false);
    (new \MuseumCalendar\Ics\FeedRepository())->load(true);
} elseif (\MuseumCalendar\Admin\Settings::calendar() === null) {
    $env = (string) @file_get_contents(ABSPATH . 'workspace/.env');
    $url = preg_match('/^MUSEUM_CALENDAR_DEV_CALENDAR_URL=(.+)$/m', $env, $match) ? trim($match[1]) : '';
    try {
        $calendar = \MuseumCalendar\Ics\GoogleCalendar::fromUrl($url);
        update_option(\MuseumCalendar\Admin\Settings::OPTION, $calendar->id, false);
        update_option('museum_calendar_demo', false);
        WP_CLI::log('.envのGoogleカレンダーの公開URLを登録しました。');
    } catch (InvalidArgumentException $error) {
        if ($url !== '') {
            WP_CLI::warning('.envのURLはGoogleカレンダーの公開URLではないため登録していません。');
        }
        update_option('museum_calendar_demo', true);
    }
}

$page = get_page_by_path('calendar-demo', OBJECT, 'page');
if (!$page) {
    $id = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_name' => 'calendar-demo',
        'post_title' => 'カレンダー動作確認',
        'post_content' => '<!-- wp:shortcode -->[museum_calendar]<!-- /wp:shortcode -->',
    ], true);
    if (is_wp_error($id)) {
        WP_CLI::error($id->get_error_message());
    }
}
if (defined('MUSEUM_CALENDAR_TEST_FIXTURES') && MUSEUM_CALENDAR_TEST_FIXTURES && !get_page_by_path('calendar-today', OBJECT, 'page')) {
    $id = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_name' => 'calendar-today',
        'post_title' => '本日の予定の動作確認',
        'post_content' => '<!-- wp:shortcode -->[museum_calendar_today]<!-- /wp:shortcode --><!-- wp:shortcode -->[museum_calendar_today lang="en"]<!-- /wp:shortcode -->',
    ], true);
    if (is_wp_error($id)) {
        WP_CLI::error($id->get_error_message());
    }
}
WP_CLI::success('確認ページを準備しました。既存ページの内容と設定済みのカレンダーは維持しています。');
