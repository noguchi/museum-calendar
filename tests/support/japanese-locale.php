<?php
/**
 * Returns a function that runs a callback with the site in Japanese.
 * The test site is in en_US. The fixture plugin supplies the Japanese weekday and month names.
 */
return static function (callable $render): string {
    if (!switch_to_locale('ja')) {
        throw new RuntimeException('The test site could not switch to Japanese.');
    }
    try {
        return $render();
    } finally {
        restore_previous_locale();
    }
};
