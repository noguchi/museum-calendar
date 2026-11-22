<?php
/** Plugin Name: Museum Calendar Development Calendar (development only) */
if (!defined('ABSPATH') || wp_get_environment_type() !== 'development') {
    return;
}
// 開発環境は、コミットしたサンプルカレンダーの写しを読み、Googleには接続しない。
add_filter('pre_http_request', static function ($pre, $args, $url) {
    if ($url !== 'https://calendar.google.com/calendar/ical/museum-dev-copy%40group.calendar.google.com/public/basic.ics') {
        return $pre;
    }
    return [
        'response' => ['code' => 200],
        'headers' => ['content-type' => 'text/calendar'],
        'body' => file_get_contents(__DIR__ . '/calendar.ics'),
    ];
}, 10, 3);
