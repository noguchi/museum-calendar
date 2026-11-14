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
