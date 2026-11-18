<?php
/**
 * Builds the copy of the real site's front page from .local/site/seed. It imports no images: the copy shows HTML and CSS only. Idempotent: it keeps pages and posts it finds.
 * Runs through WP-CLI in the development environment, and from the activation of the staging seed plugin.
 */
if (!defined('ABSPATH')) {
    exit(1);
}

function museum_calendar_site_seed(string $seed, string $theme): void
{
    if (!wp_get_theme($theme)->exists()) {
        throw new RuntimeException("テーマ {$theme} がありません。");
    }
    if (get_stylesheet() !== $theme) {
        switch_theme($theme);
    }

    $pages = [];
    foreach (['top' => ['トップ', ''], 'news' => ['NEWS', ''], 'en' => ['English', ''], 'gallery' => ['フォトギャラリー', '']] as $slug => [$title, $content]) {
        $page = get_page_by_path($slug, OBJECT, 'page');
        $pages[$slug] = $page ? $page->ID : wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content], true);
        if (is_wp_error($pages[$slug])) {
            throw new RuntimeException($pages[$slug]->get_error_message());
        }
    }

    $posts = json_decode((string) file_get_contents($seed . '/posts.json'), true, 16, JSON_THROW_ON_ERROR);
    foreach ($posts as $post) {
        $key = md5($post['title']);
        if (get_posts(['post_type' => 'post', 'post_status' => 'any', 'meta_key' => '_museum_calendar_site_seed', 'meta_value' => $key, 'fields' => 'ids', 'numberposts' => 1])) {
            continue;
        }
        $categories = [];
        foreach ($post['categories'] as $category) {
            $term = get_term_by('slug', $category['slug'], 'category') ?: (object) wp_insert_term($category['name'], 'category', ['slug' => $category['slug']]);
            $categories[] = (int) $term->term_id;
        }
        $id = wp_insert_post([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => $post['title'],
            'post_date' => $post['date'],
            'post_content' => '<!-- wp:paragraph --><p>トップページの確認用に、本番のタイトルだけを写した記事です。</p><!-- /wp:paragraph -->',
            'post_category' => $categories,
            'meta_input' => ['_museum_calendar_site_seed' => $key],
        ], true);
        if (is_wp_error($id)) {
            throw new RuntimeException($id->get_error_message());
        }
        if ($post['sticky']) {
            stick_post($id);
        }
    }

    update_option('show_on_front', 'page');
    update_option('page_on_front', $pages['top']);
    update_option('page_for_posts', $pages['news']);
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
    $seed = ABSPATH . 'workspace/.local/site/seed';
    if (!is_file($seed . '/posts.json')) {
        WP_CLI::error('.local/site/seed がありません。docs/development.md の「実サイトのトップページ」を参照してください。');
    }
    try {
        museum_calendar_site_seed($seed, 'otani-museum2026');
    } catch (Throwable $error) {
        WP_CLI::error($error->getMessage());
    }
    WP_CLI::success('実サイトのトップページを準備しました。既存のページ・投稿は維持しています。');
}
