<?php
/**
 * Returns a function that runs a callback with Japanese weekday and month names in the WordPress locale.
 * The ja locale is not installed on the test site, so these names stand in for it.
 */
return static function (callable $render): string {
    $locale = $GLOBALS['wp_locale'];
    $GLOBALS['wp_locale'] = clone $locale;
    foreach (['日', '月', '火', '水', '木', '金', '土'] as $number => $name) {
        $GLOBALS['wp_locale']->weekday_abbrev[$locale->get_weekday($number)] = $name;
    }
    foreach (array_keys($locale->month) as $number) {
        $GLOBALS['wp_locale']->month[$number] = (int) $number . '月';
    }
    try {
        return $render();
    } finally {
        $GLOBALS['wp_locale'] = $locale;
    }
};
