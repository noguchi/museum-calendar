<?php
/** 手元の環境に確認用のページと設定を作る。WP-CLIから実行し、何度実行しても同じ結果になる。 */
if (!defined('WP_CLI') || !WP_CLI) {
    exit(1);
}
if (!in_array(wp_get_environment_type(), ['local', 'development'], true)) {
    WP_CLI::error('サンプルデータは開発環境にのみ作成できます。');
}
update_option('timezone_string', 'Asia/Tokyo');
if (defined('MUSEUM_CALENDAR_TEST_FIXTURES') && MUSEUM_CALENDAR_TEST_FIXTURES) {
    // ブラウザーテストは月曜始まりを前提にする。開発環境では管理者が選んだ週の始まりを変えない。
    update_option('start_of_week', 1);
    update_option(\MuseumCalendar\Admin\Settings::OPTION, 'museum-fixture@group.calendar.google.com', false);
    delete_option(\MuseumCalendar\Admin\Settings::SECRET_OPTION);
    delete_option('museum_calendar_feed');
    (new \MuseumCalendar\Ics\FeedRepository())->load(true);
} elseif (\MuseumCalendar\Admin\Settings::calendar() === null) {
    // dev/calendar-copy が、このカレンダーの取得に対してコミット済みのサンプルカレンダーの写しを返す。
    update_option(\MuseumCalendar\Admin\Settings::OPTION, 'museum-dev-copy@group.calendar.google.com', false);
    delete_option(\MuseumCalendar\Admin\Settings::SECRET_OPTION);
    (new \MuseumCalendar\Ics\FeedRepository())->load(true);
    WP_CLI::log('サンプルカレンダーの写し（dev/calendar-copy/calendar.ics）を登録しました。');
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
