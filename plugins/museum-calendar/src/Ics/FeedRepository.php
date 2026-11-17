<?php
declare(strict_types=1);

namespace MuseumCalendar\Ics;

use MuseumCalendar\Admin\Settings;
use MuseumCalendar\Calendar\Month;
use RuntimeException;
use Throwable;

final class FeedRepository
{
    public const CACHE = 'museum_calendar_feed';
    public const LOCK = 'museum_calendar_feed_lock';
    public const INTERVAL = 900;
    public const MAX_AGE = 86400;

    /** @return array{body:string, fetched_at:int, attempted_at:int, error:string, hash:string, stale:bool} */
    public function load(bool $force = false): array
    {
        $url = Settings::calendar()?->feedUrl();
        if ($url === null) {
            throw new RuntimeException('not_configured');
        }
        $hash = hash('sha256', $url);
        $state = get_option(self::CACHE, []);
        if (!is_array($state) || ($state['hash'] ?? '') !== $hash) {
            $state = ['body' => '', 'fetched_at' => 0, 'attempted_at' => 0, 'error' => '', 'hash' => $hash];
        }
        $retryAfter = $state['error'] === '' ? self::INTERVAL : 300;
        if (!$force && $state['attempted_at'] + $retryAfter > time()) {
            return $this->usable($state);
        }
        if ((int) get_option(self::LOCK, 0) < time() - 60) {
            delete_option(self::LOCK);
        }
        if (!add_option(self::LOCK, time(), '', false)) {
            return $this->usable($state);
        }
        try {
            $state['attempted_at'] = time();
            try {
                $response = wp_safe_remote_get($url, [
                    'timeout' => 10,
                    'redirection' => 0,
                    'limit_response_size' => Parser::MAX_BYTES + 1,
                    'headers' => ['Accept' => 'text/calendar'],
                    'cookies' => [],
                ]);
                if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                    throw new RuntimeException('fetch_failed');
                }
                $body = wp_remote_retrieve_body($response);
                // Validate before replacing the last known good feed.
                (new Parser())->forMonth($body, new Month(wp_date('Y-m', null, new \DateTimeZone('Asia/Tokyo'))));
                $state['body'] = $body;
                $state['fetched_at'] = time();
                $state['error'] = '';
            } catch (Throwable $error) {
                // Exception messages can contain event data.
                $state['error'] = '更新に失敗しました。カレンダーが一般公開され、予定の詳細を表示する設定になっているか確認してください。';
            }
            // A settings change during a request must not restore the old feed.
            if (hash('sha256', Settings::calendar()?->feedUrl() ?? '') !== $hash) {
                throw new RuntimeException('configuration_changed');
            }
            update_option(self::CACHE, $state, false);
        } finally {
            delete_option(self::LOCK);
        }
        return $this->usable($state);
    }

    private function usable(array $state): array
    {
        if ($state['body'] === '' || time() - $state['fetched_at'] > self::MAX_AGE) {
            throw new RuntimeException('feed_unavailable');
        }
        $state['stale'] = $state['error'] !== '' || time() - $state['fetched_at'] >= self::INTERVAL;
        return $state;
    }

    public static function clear(): void
    {
        delete_option(self::CACHE);
    }

    public static function refresh(): void
    {
        try {
            (new self())->load();
        } catch (Throwable $error) {
            // Status is saved by load(). Exceptions can contain event data.
        }
    }
}
