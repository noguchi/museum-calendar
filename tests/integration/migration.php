<?php
/** Exercise the rename against actual WordPress options and shortcode rendering. */
use MuseumCalendar\Admin\Settings;
use MuseumCalendar\Ics\FeedRepository;
use MuseumCalendar\LegacyMigration;

if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'development') {
    exit(1);
}
$keys = [Settings::OPTION, FeedRepository::CACHE, 'museum_calendar_demo', LegacyMigration::MARKER,
    'otani_calendar_ics_url', 'otani_calendar_feed', 'otani_calendar_demo', 'cron'];
$saved = [];
foreach ($keys as $key) {
    $saved[$key] = get_option($key, null);
    delete_option($key);
}
$query = $_GET;
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};
try {
    $url = 'https://calendar.google.com/museum-fixture/migration/basic.ics';
    $cache = ['body' => file_get_contents(dirname(__DIR__) . '/fixtures/museum.ics'),
        'fetched_at' => time(), 'attempted_at' => time(), 'error' => '', 'hash' => hash('sha256', $url)];
    update_option('otani_calendar_ics_url', $url, false);
    update_option('otani_calendar_feed', $cache, false);
    add_option('otani_calendar_demo', false, '', false);
    wp_schedule_single_event(time() + 300, 'otani_calendar_refresh_feed');
    LegacyMigration::run();
    $check(Settings::url() === $url, 'Legacy ICS URL was not preserved.');
    $check(get_option(FeedRepository::CACHE) === $cache, 'Legacy cache was not preserved.');
    $check(get_option('museum_calendar_demo', null) !== null && !get_option('museum_calendar_demo'), 'Demo state was not preserved.');
    $check(!wp_next_scheduled('otani_calendar_refresh_feed'), 'Legacy cron was not removed.');
    $check(get_option('otani_calendar_ics_url') === $url, 'Original settings were changed.');
    $check(get_option(LegacyMigration::MARKER) !== false, 'Migration completion was not recorded.');
    $html = do_shortcode('[museum_calendar month="2026-11"]');
    $check(str_contains($html, '終日展示'), 'Migrated feed was not rendered.');
    $check(do_shortcode('[otani_calendar month="2026-11"]') === $html, 'Legacy shortcode changed output.');
    $_GET = ['otani_month' => '2026-11'];
    $check(str_contains(do_shortcode('[otani_calendar]'), '2026年11月'), 'Legacy month link failed.');
    $_GET['museum_month'] = '2026-12';
    $check(str_contains(do_shortcode('[museum_calendar]'), '2026年12月'), 'New month parameter must take precedence.');

    // A disconnected or changed feed must never be restored by a later request.
    update_option(Settings::OPTION, '', false);
    FeedRepository::clear();
    LegacyMigration::run();
    $check(Settings::url() === '' && get_option(FeedRepository::CACHE, null) === null, 'Migration restored disconnected data.');
    delete_option(LegacyMigration::MARKER);
    LegacyMigration::run();
    $check(Settings::url() === '' && get_option(FeedRepository::CACHE, null) === null, 'Initial migration overwrote explicit disconnect.');
    update_option(Settings::OPTION, 'https://calendar.google.com/museum-fixture/new/basic.ics', false);
    delete_option(LegacyMigration::MARKER);
    LegacyMigration::run();
    $check(str_contains(Settings::url(), '/new/'), 'Migration replaced an existing new setting.');

    global $wpdb;
    $autoload = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Settings::OPTION));
    $check(in_array($autoload, ['no', 'off', 'auto-off'], true), 'Secret option must not autoload.');
} finally {
    $_GET = $query;
    foreach ($saved as $key => $value) {
        if ($value === null) {
            delete_option($key);
        } else {
            update_option($key, $value, false);
        }
    }
}
WP_CLI::success("Rename migration: {$checks} checks passed.");
