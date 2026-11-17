<?php
/** Plugin Name: Museum Calendar Test Fixtures (tests only) */
if (!defined('ABSPATH') || !defined('MUSEUM_CALENDAR_TEST_FIXTURES') || !MUSEUM_CALENDAR_TEST_FIXTURES
    || wp_get_environment_type() !== 'development') {
    return;
}
add_filter('pre_http_request', static function ($pre, $args, $url) {
    if ($url !== 'https://calendar.google.com/museum-fixture/browser/basic.ics') {
        return $pre;
    }
    return [
        'response' => ['code' => 200],
        'headers' => ['content-type' => 'text/calendar'],
        'body' => file_get_contents(ABSPATH . 'workspace/tests/fixtures/museum.ics'),
    ];
}, 10, 3);

// The test site installs no language pack. These two filters let switch_to_locale('ja') load the names in ja.l10n.php.
add_filter('get_available_languages', static fn (array $languages): array => array_values(array_unique([...$languages, 'ja'])));
add_filter('load_translation_file', static function (string $file, string $domain, string $locale): string {
    return $domain === 'default' && $locale === 'ja' && basename($file) === 'ja.l10n.php' ? __DIR__ . '/ja.l10n.php' : $file;
}, 10, 3);
