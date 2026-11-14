<?php
use MuseumCalendar\Admin\Settings;
use MuseumCalendar\Ics\FeedRepository;
use MuseumCalendar\Ics\Parser;

if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'development') {
    exit(1);
}
$saved = [];
foreach ([Settings::OPTION, FeedRepository::CACHE, FeedRepository::LOCK, 'museum_calendar_demo'] as $key) {
    $saved[$key] = get_option($key, null);
    delete_option($key);
}
$oldUser = get_current_user_id();
wp_set_current_user(1);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$fixture = file_get_contents(dirname(__DIR__) . '/fixtures/museum.ics');
$body = $fixture;
$mode = 'ok';
$requests = 0;
$mock = static function ($pre, $args, $url) use (&$body, &$mode, &$requests, $check) {
    if (!str_contains($url, '/museum-fixture/')) {
        return $pre;
    }
    $requests++;
    $check($args['reject_unsafe_urls'] === true && $args['redirection'] === 0, 'Unsafe HTTP options.');
    $check($args['limit_response_size'] === Parser::MAX_BYTES + 1, 'Missing response size limit.');
    if ($mode === 'timeout') {
        return new WP_Error('timeout', 'Secret URL must never leak: ' . $url);
    }
    return ['response' => ['code' => $mode === 'http_error' ? 403 : 200], 'body' => $body, 'headers' => []];
};
add_filter('pre_http_request', $mock, 10, 3);
$url = 'https://calendar.google.com/museum-fixture/private-secret/basic.ics';
$feed = new FeedRepository();
try {
    foreach (['http://calendar.google.com/a.ics', 'https://127.0.0.1/a.ics', 'https://localhost/a.ics', 'https://169.254.169.254/a.ics', 'https://user:password@calendar.google.com/a.ics', 'https://calendar.google.com:8443/a.ics', 'file:///etc/passwd'] as $badUrl) {
        $check(is_wp_error(Settings::save(['url' => $badUrl])), 'Unsafe URL was accepted.');
    }
    $check(Settings::save(['url' => $url]) === true, 'Valid HTTPS URL was rejected.');
    $check(Settings::save(['url' => '']) === true && Settings::url() === $url, 'Empty field must keep the secret.');
    $check(!get_option('museum_calendar_demo'), 'ICS settings must disable demo data.');
    $first = $feed->load();
    $check($first['body'] === $fixture && !$first['stale'], 'First fetch failed.');
    $feed->load();
    $check($requests === 1, 'Fresh cache made another HTTP request.');
    $html = do_shortcode('[museum_calendar month="2026-11"]');
    $check(str_contains($html, '日時変更ガイド') && str_contains($html, '終日展示'), 'ICS events were not rendered.');
    $check(!str_contains($html, '動作確認用サンプル') && !str_contains($html, 'private-secret'), 'Demo label or secret leaked.');

    ob_start();
    Settings::page();
    $adminHtml = ob_get_clean();
    $check(!str_contains($adminHtml, 'private-secret'), 'Stored URL leaked into settings HTML.');
    $check(str_contains($adminHtml, '_wpnonce') && str_contains($adminHtml, '最終成功'), 'Admin form/status missing.');

    $mode = 'timeout';
    $stale = $feed->load(true);
    $check($stale['stale'] && $stale['body'] === $fixture, 'Failed fetch discarded last good data.');
    $check(!str_contains($stale['error'], $url), 'Error exposed a secret URL.');
    $html = do_shortcode('[museum_calendar month="2026-11"]');
    $check(str_contains($html, '前回取得した予定'), 'Stale data was shown without a notice.');
    $before = $requests;
    $feed->load();
    $check($requests === $before, 'Failure retry backoff was ignored.');

    $state = get_option(FeedRepository::CACHE);
    $state['fetched_at'] = time() - FeedRepository::MAX_AGE - 1;
    update_option(FeedRepository::CACHE, $state, false);
    $html = do_shortcode('[museum_calendar month="2026-11"]');
    $check(str_contains($html, '現在、予定を取得できません') && !str_contains($html, '終日展示'), 'Expired stale data remained visible.');

    $mode = 'ok';
    $feed->load(true);
    foreach (['<html>not a calendar</html>', str_repeat('x', Parser::MAX_BYTES + 1)] as $invalid) {
        $body = $invalid;
        $result = $feed->load(true);
        $check($result['body'] === $fixture && $result['stale'], 'Invalid response replaced valid cache.');
    }
    $body = "BEGIN:VCALENDAR\nVERSION:2.0\nEND:VCALENDAR";
    $result = $feed->load(true);
    $check($result['body'] === $body && !$result['stale'], 'Empty feed must remove deleted events.');
    $check(str_contains(do_shortcode('[museum_calendar month="2026-11"]'), 'この月の予定はありません'), 'Empty calendar message missing.');

    Settings::save(['url' => 'https://calendar.google.com/museum-fixture/another-secret/basic.ics']);
    $check(get_option(FeedRepository::CACHE, null) === null, 'Changed source retained previous source data.');
    $mode = 'http_error';
    $check(str_contains(do_shortcode('[museum_calendar]'), '現在、予定を取得できません'), 'Initial HTTP failure was not handled.');
    $mode = 'ok';
    $body = $fixture;
    $feed->load(true);
    $state = get_option(FeedRepository::CACHE);
    $state['attempted_at'] = time() - 901;
    update_option(FeedRepository::CACHE, $state, false);
    $before = $requests;
    $feed->load();
    $check($requests === $before + 1, 'Expired cache was not refreshed.');

    wp_set_current_user(0);
    $check(is_wp_error(Settings::save(['disconnect' => '1'])), 'Unauthorized settings change was allowed.');
    $check(Settings::url() !== '', 'Unauthorized request changed configuration.');
    wp_set_current_user(1);
    Settings::save(['disconnect' => '1']);
    $check(Settings::url() === '' && get_option(FeedRepository::CACHE, null) === null, 'Disconnect did not clear the source and feed.');
} finally {
    remove_filter('pre_http_request', $mock, 10);
    foreach ($saved as $key => $value) {
        if ($value === null) {
            delete_option($key);
        } else {
            update_option($key, $value, false);
        }
    }
    wp_set_current_user($oldUser);
}
WP_CLI::success("ICS integration: {$checks} checks passed.");
