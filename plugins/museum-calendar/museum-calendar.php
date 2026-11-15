<?php
/**
 * Plugin Name: Museum Calendar for WordPress
 * Plugin URI: https://github.com/noguchi/museum-calendar
 * Description: ICS形式のカレンダーを取得し、日本時間の月間カレンダーとして表示します。
 * Version: 0.3.0
 * Requires at least: 6.8
 * Requires PHP: 8.3
 * License: GPL-2.0-or-later
 * Text Domain: museum-calendar
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
if (!is_readable(__DIR__ . '/vendor/autoload.php')) {
    add_action('admin_notices', static function (): void {
        if (current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>Museum Calendarの依存ライブラリがありません。開発環境ではnpm run setupを実行し、配布時はvendorを含むZIPを使用してください。</p></div>';
        }
    });
    return;
}
require_once __DIR__ . '/vendor/autoload.php';

\MuseumCalendar\Admin\Settings::register();

add_filter('cron_schedules', static function (array $schedules): array {
    $schedules['museum_calendar_quarter_hour'] = ['interval' => 900, 'display' => 'Museum Calendar: 15 minutes'];
    return $schedules;
});
add_action('museum_calendar_refresh_feed', [\MuseumCalendar\Ics\FeedRepository::class, 'refresh']);
register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('museum_calendar_refresh_feed');
});

add_action('wp_enqueue_scripts', static function (): void {
    $post = get_queried_object();
    if ($post instanceof \WP_Post && has_shortcode($post->post_content, 'museum_calendar')) {
        wp_enqueue_style('museum-calendar', plugins_url('assets/calendar.css', __FILE__), [], '0.3.0');
    }
});

add_action('init', static function (): void {
    if (\MuseumCalendar\Admin\Settings::url() !== '') {
        if (!wp_next_scheduled('museum_calendar_refresh_feed')) {
            wp_schedule_event(time() + 900, 'museum_calendar_quarter_hour', 'museum_calendar_refresh_feed');
        }
    } else {
        wp_clear_scheduled_hook('museum_calendar_refresh_feed');
    }
    $render = static function ($attributes): string {
        $attributes = shortcode_atts(['month' => ''], (array) $attributes, 'museum_calendar');
        $requested = $attributes['month'];
        $queryMonth = $_GET['museum_month'] ?? '';
        if ($requested === '' && is_string($queryMonth)) {
            $requested = sanitize_text_field(wp_unslash($queryMonth));
        }
        try {
            $month = new \MuseumCalendar\Calendar\Month($requested ?: wp_date('Y-m', null, new \DateTimeZone('Asia/Tokyo')));
        } catch (\InvalidArgumentException $exception) {
            return '<p class="museum-calendar-notice">年月の指定が正しくありません。</p>';
        }
        $demo = false;
        $notice = '';
        if (\MuseumCalendar\Admin\Settings::url() !== '') {
            try {
                $feed = (new \MuseumCalendar\Ics\FeedRepository())->load();
                $source = new \MuseumCalendar\Ics\Source($feed['body']);
                if ($feed['stale']) {
                    $notice = '更新を確認できていないため、前回取得した予定を表示しています。最終取得：'
                        . wp_date('Y/m/d H:i', $feed['fetched_at'], new \DateTimeZone('Asia/Tokyo')) . '（日本時間）';
                }
            } catch (\Throwable $error) {
                return '<p class="museum-calendar-notice" role="status">現在、予定を取得できません。時間をおいてご確認ください。</p>';
            }
        } elseif (in_array(wp_get_environment_type(), ['local', 'development'], true) && get_option('museum_calendar_demo', false)) {
            $source = new \MuseumCalendar\Calendar\DemoSource();
            $demo = true;
        } else {
            return '<p class="museum-calendar-notice">カレンダーの接続が設定されていません。</p>';
        }
        wp_enqueue_style('museum-calendar', plugins_url('assets/calendar.css', __FILE__), [], '0.3.0');
        try {
            return (new \MuseumCalendar\Frontend\Renderer($source, $demo, $notice))
                ->render($month, $attributes['month'] === '');
        } catch (\Throwable $error) {
            return '<p class="museum-calendar-notice" role="status">この月の予定を表示できません。</p>';
        }
    };
    add_shortcode('museum_calendar', $render);
});
