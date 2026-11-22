<?php
/**
 * 実サイトのトップページの写し（テーマと、本日の予定とカレンダーを表示する2つのページ）を作る。
 * 何度実行しても同じ結果になる。すでにあるページはそのまま残す。
 * 開発環境ではWP-CLIから、ステージングでは写しを設定するプラグインの有効化から実行する。
 */
if (!defined('ABSPATH')) {
    exit(1);
}

function museum_calendar_site_seed(string $theme): void
{
    if (!wp_get_theme($theme)->exists()) {
        throw new RuntimeException("テーマ {$theme} がありません。");
    }
    if (get_stylesheet() !== $theme) {
        switch_theme($theme);
    }

    $pages = [];
    foreach (['top' => 'トップ', 'en' => 'English'] as $slug => $title) {
        $page = get_page_by_path($slug, OBJECT, 'page');
        $pages[$slug] = $page ? $page->ID : wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title], true);
        if (is_wp_error($pages[$slug])) {
            throw new RuntimeException($pages[$slug]->get_error_message());
        }
    }

    update_option('show_on_front', 'page');
    update_option('page_on_front', $pages['top']);
    update_option('timezone_string', 'Asia/Tokyo');
    update_option('start_of_week', 1);
    update_option('blog_public', 0);
    if (get_option('permalink_structure') !== '/%postname%/') {
        global $wp_rewrite;
        $wp_rewrite->set_permalink_structure('/%postname%/');
        $wp_rewrite->flush_rules();
    }
}

if (defined('WP_CLI') && WP_CLI) {
    if (!in_array(wp_get_environment_type(), ['local', 'development'], true)) {
        WP_CLI::error('WP-CLIからの複製は開発環境だけで実行できます。');
    }
    try {
        museum_calendar_site_seed('otani-museum2026');
    } catch (Throwable $error) {
        WP_CLI::error($error->getMessage() . ' docs/development.md の「実サイトのトップページ」を参照してください。');
    }
    WP_CLI::success('実サイトのトップページを準備しました。既存のページは維持しています。');
}
