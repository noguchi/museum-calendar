<?php
/**
 * Plugin Name: Museum Calendar for WordPress
 * Plugin URI: https://github.com/noguchi/museum-calendar
 * Description: 一般公開したGoogleカレンダーの予定を取得し、日本時間の月間カレンダーと本日の予定を表示します。
 * Version: 0.8.0
 * Requires at least: 7.1.4
 * Requires PHP: 8.5.12
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
add_action('init', [\MuseumCalendar\Admin\Settings::class, 'migrate'], 0);

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
    if (!$post instanceof \WP_Post) {
        return;
    }
    if (has_shortcode($post->post_content, 'museum_calendar') || has_shortcode($post->post_content, 'museum_calendar_today')) {
        wp_enqueue_style('museum-calendar');
    }
});

add_action('init', static function (): void {
    if (\MuseumCalendar\Admin\Settings::calendar() !== null) {
        if (!wp_next_scheduled('museum_calendar_refresh_feed')) {
            wp_schedule_event(time() + 900, 'museum_calendar_quarter_hour', 'museum_calendar_refresh_feed');
        }
    } else {
        wp_clear_scheduled_hook('museum_calendar_refresh_feed');
    }
    // A theme that calls do_shortcode() from a template runs too late for the head, so it enqueues this handle itself.
    wp_register_style('museum-calendar', plugins_url('assets/calendar.css', __FILE__), [], '0.8.0');

    $notice = static fn (\MuseumCalendar\Frontend\Language $language, string $key, string $role = ''): string
        => '<p class="museum-calendar-notice"' . $role . $language->attribute() . '>' . esc_html($language->text($key)) . '</p>';

    $renderer = static function (\MuseumCalendar\Frontend\Language $language) use ($notice): \MuseumCalendar\Frontend\Renderer|string {
        $demo = false;
        $stale = '';
        if (\MuseumCalendar\Admin\Settings::calendar() !== null) {
            try {
                $feed = (new \MuseumCalendar\Ics\FeedRepository())->load();
                $source = new \MuseumCalendar\Ics\Source($feed['body']);
                if ($feed['stale']) {
                    $stale = $language->text('stale', wp_date('Y/m/d H:i', $feed['fetched_at'], new \DateTimeZone('Asia/Tokyo')));
                }
            } catch (\Throwable $error) {
                return $notice($language, 'unavailable', ' role="status"');
            }
        } elseif (in_array(wp_get_environment_type(), ['local', 'development'], true) && get_option('museum_calendar_demo', false)) {
            $source = new \MuseumCalendar\Calendar\DemoSource();
            $demo = true;
        } else {
            return $notice($language, 'not_connected');
        }
        return new \MuseumCalendar\Frontend\Renderer($source, $demo, $stale, $language);
    };

    // The language is unknown when the attribute is invalid, so that notice is in the plugin's own language.
    $inLanguage = static function (string $lang, callable $render) use ($notice): string {
        $language = \MuseumCalendar\Frontend\Language::fromAttribute($lang);
        return $language === null
            ? $notice(\MuseumCalendar\Frontend\Language::Japanese, 'invalid_language')
            : $language->withLocale(static fn (): string => $render($language));
    };

    add_shortcode('museum_calendar', static function ($attributes) use ($renderer, $notice, $inLanguage): string {
        $attributes = shortcode_atts(['month' => '', 'lang' => ''], (array) $attributes, 'museum_calendar');
        return $inLanguage($attributes['lang'], static function (\MuseumCalendar\Frontend\Language $language) use ($attributes, $renderer, $notice): string {
            $requested = $attributes['month'];
            $queryMonth = $_GET['museum_month'] ?? '';
            if ($requested === '' && is_string($queryMonth)) {
                $requested = sanitize_text_field(wp_unslash($queryMonth));
            }
            $today = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo'));
            try {
                $month = new \MuseumCalendar\Calendar\Month($requested ?: $today->format('Y-m'));
            } catch (\InvalidArgumentException $exception) {
                return $notice($language, 'invalid_month');
            }
            $calendar = $renderer($language);
            if (is_string($calendar)) {
                return $calendar;
            }
            wp_enqueue_style('museum-calendar');
            // WordPress numbers the weekdays 0 (Sunday) to 6. The plugin uses ISO 8601 numbers, where Sunday is 7.
            $startOfWeek = filter_var(get_option('start_of_week'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 6]]);
            $firstWeekday = match ($startOfWeek) {
                false => 1,
                0 => 7,
                default => $startOfWeek,
            };
            try {
                return $calendar->render($month, $today, $attributes['month'] === '', $firstWeekday);
            } catch (\Throwable $error) {
                return $notice($language, 'month_failed', ' role="status"');
            }
        });
    });

    add_shortcode('museum_calendar_today', static function ($attributes) use ($renderer, $notice, $inLanguage): string {
        $attributes = shortcode_atts(['lang' => ''], (array) $attributes, 'museum_calendar_today');
        return $inLanguage($attributes['lang'], static function (\MuseumCalendar\Frontend\Language $language) use ($renderer, $notice): string {
            $calendar = $renderer($language);
            if (is_string($calendar)) {
                return $calendar;
            }
            wp_enqueue_style('museum-calendar');
            try {
                return $calendar->renderToday(new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo')), $language->dateFormat(get_option('date_format')));
            } catch (\Throwable $error) {
                return $notice($language, 'today_failed', ' role="status"');
            }
        });
    });
});
