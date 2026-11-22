<?php
/**
 * 管理者がプラグインを削除したとき、プラグインが保存したものを非公開URLの秘密の部分も含めてすべて削除する。
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// vendor がなくても削除できるように、オプション名を直接書く。統合テストが Settings と FeedRepository の定数と一致することを検査する。
foreach ([
    'museum_calendar_google_calendar',
    'museum_calendar_google_calendar_secret',
    'museum_calendar_kinds',
    'museum_calendar_feed',
    'museum_calendar_feed_lock',
] as $option) {
    delete_option($option);
}
wp_clear_scheduled_hook('museum_calendar_refresh_feed');
