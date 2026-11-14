<?php
declare(strict_types=1);

namespace MuseumCalendar;

use MuseumCalendar\Admin\Settings;
use MuseumCalendar\Ics\FeedRepository;

/** Preserve settings from the original plugin without rewriting page content. */
final class LegacyMigration
{
    public const MARKER = 'museum_calendar_legacy_migrated';

    public static function run(): void
    {
        if (get_option(self::MARKER, false)) {
            return;
        }
        // Never replace a new installation's settings, including an explicit disconnect.
        if (get_option(Settings::OPTION, null) === null) {
            foreach ([
                'otani_calendar_ics_url' => Settings::OPTION,
                'otani_calendar_feed' => FeedRepository::CACHE,
                'otani_calendar_demo' => 'museum_calendar_demo',
            ] as $old => $new) {
                $value = get_option($old, null);
                if ($value !== null) {
                    add_option($new, $value, '', false);
                }
            }
        }
        wp_clear_scheduled_hook('otani_calendar_refresh_feed');
        add_option(self::MARKER, true, '', false);
    }
}
