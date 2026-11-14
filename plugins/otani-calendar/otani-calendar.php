<?php
/**
 * Plugin Name: Otani Calendar
 * Description: ICS形式のカレンダーを取得し、日本時間の月間カレンダーとして表示します。
 * Version: 0.2.0
 * Requires at least: 6.8
 * Requires PHP: 8.3
 * License: GPL-2.0-or-later
 * Text Domain: otani-calendar
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
if (!is_readable(__DIR__ . '/vendor/autoload.php')) {
    add_action('admin_notices', static function (): void {
        if (current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>Otani Calendarの依存ライブラリがありません。開発環境ではnpm run setupを実行し、配布時はvendorを含むZIPを使用してください。</p></div>';
        }
    });
    return;
}
require_once __DIR__ . '/vendor/autoload.php';

\OtaniCalendar\Admin\Settings::register();

add_filter('cron_schedules', static function (array $schedules): array {
    $schedules['otani_calendar_quarter_hour'] = ['interval' => 900, 'display' => 'Otani Calendar: 15 minutes'];
    return $schedules;
});
add_action('otani_calendar_refresh_feed', [\OtaniCalendar\Ics\FeedRepository::class, 'refresh']);
register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('otani_calendar_refresh_feed');
});

add_action('wp_enqueue_scripts', static function (): void {
    $post = get_queried_object();
    if ($post instanceof \WP_Post && has_shortcode($post->post_content, 'otani_calendar')) {
        wp_enqueue_style('otani-calendar', plugins_url('assets/calendar.css', __FILE__), [], '0.2.0');
    }
});

add_action('init', static function (): void {
    if (\OtaniCalendar\Admin\Settings::url() !== '') {
        if (!wp_next_scheduled('otani_calendar_refresh_feed')) {
            wp_schedule_event(time() + 900, 'otani_calendar_quarter_hour', 'otani_calendar_refresh_feed');
        }
    } else {
        wp_clear_scheduled_hook('otani_calendar_refresh_feed');
    }
    add_shortcode('otani_calendar', static function ($attributes): string {
        $attributes = shortcode_atts(['month' => ''], (array) $attributes, 'otani_calendar');
        $requested = $attributes['month'];
        if ($requested === '' && isset($_GET['otani_month'])) {
            $requested = is_string($_GET['otani_month'])
                ? sanitize_text_field(wp_unslash($_GET['otani_month'])) : '';
        }
        try {
            $month = new \OtaniCalendar\Calendar\Month($requested ?: wp_date('Y-m', null, new \DateTimeZone('Asia/Tokyo')));
        } catch (\InvalidArgumentException $exception) {
            return '<p class="otani-calendar-notice">年月の指定が正しくありません。</p>';
        }
        $demo = false;
        $notice = '';
        if (\OtaniCalendar\Admin\Settings::url() !== '') {
            try {
                $feed = (new \OtaniCalendar\Ics\FeedRepository())->load();
                $source = new \OtaniCalendar\Ics\Source($feed['body']);
                if ($feed['stale']) {
                    $notice = '更新を確認できていないため、前回取得した予定を表示しています。最終取得：'
                        . wp_date('Y/m/d H:i', $feed['fetched_at'], new \DateTimeZone('Asia/Tokyo')) . '（日本時間）';
                }
            } catch (\Throwable $error) {
                return '<p class="otani-calendar-notice" role="status">現在、予定を取得できません。時間をおいてご確認ください。</p>';
            }
        } elseif (in_array(wp_get_environment_type(), ['local', 'development'], true) && get_option('otani_calendar_demo', false)) {
            $source = new \OtaniCalendar\Calendar\DemoSource();
            $demo = true;
        } else {
            return '<p class="otani-calendar-notice">カレンダーの接続が設定されていません。</p>';
        }
        wp_enqueue_style('otani-calendar', plugins_url('assets/calendar.css', __FILE__), [], '0.2.0');
        try {
            return (new \OtaniCalendar\Frontend\Renderer($source, $demo, $notice))
                ->render($month, $attributes['month'] === '');
        } catch (\Throwable $error) {
            return '<p class="otani-calendar-notice" role="status">この月の予定を表示できません。</p>';
        }
    });
});
