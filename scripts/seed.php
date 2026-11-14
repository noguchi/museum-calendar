<?php
/** Idempotent local fixture setup, executed through WP-CLI. */
if (!defined('WP_CLI') || !WP_CLI) {
    exit(1);
}
if (!in_array(wp_get_environment_type(), ['local', 'development'], true)) {
    WP_CLI::error('サンプルデータは開発環境にのみ作成できます。');
}
update_option('timezone_string', 'Asia/Tokyo');
update_option('start_of_week', 1);
if (defined('OTANI_CALENDAR_TEST_FIXTURES') && OTANI_CALENDAR_TEST_FIXTURES) {
    update_option('otani_calendar_ics_url', 'https://calendar.google.com/otani-fixture/browser/basic.ics', false);
    delete_option('otani_calendar_feed');
    update_option('otani_calendar_demo', false);
    (new \OtaniCalendar\Ics\FeedRepository())->load(true);
} elseif (!get_option('otani_calendar_ics_url', '')) {
    update_option('otani_calendar_demo', true);
}

$page = get_page_by_path('calendar-demo', OBJECT, 'page');
if (!$page) {
    $id = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_name' => 'calendar-demo',
        'post_title' => 'カレンダー動作確認',
        'post_content' => '<!-- wp:shortcode -->[otani_calendar]<!-- /wp:shortcode -->',
    ], true);
    if (is_wp_error($id)) {
        WP_CLI::error($id->get_error_message());
    }
}
WP_CLI::success('確認ページを準備しました。既存ページの内容と設定済みICS接続は維持しています。');
