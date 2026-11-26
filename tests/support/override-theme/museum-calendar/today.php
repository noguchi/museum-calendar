<?php
if (!defined('ABSPATH')) {
    exit;
}
$variables = get_defined_vars();
try {
    $scope = self::class;
} catch (Error) {
    $scope = 'none';
}
if (apply_filters('museum_calendar_fixture_throws', false)) {
    echo 'partial today output';
    throw new RuntimeException('The fixture template failed.');
}
$lines = [
    'variables=' . implode(',', array_keys($variables)),
    'this=' . (isset($this) ? 'visible' : 'hidden'),
    'scope=' . $scope,
    'today=' . $today->format('Y-m-d') . ' ' . $today->getTimezone()->getName(),
    'date=' . $date,
    'weekday=' . $weekday,
    'status=' . ($status === null ? 'none' : sprintf('%s [%s] [%s]', $status['kind'], $status['label'], $status['hours'])),
    'lang=' . $lang,
    'text=' . implode(' | ', array_map(static fn (string $key, string $value): string => $key . ': ' . $value, array_keys($text), $text)),
];
echo "<pre class=\"override-today\">\n" . esc_html(implode("\n", $lines)) . "\n</pre>\n";
echo '<pre class="override-today-dump">' . esc_html(print_r($variables, true)) . "</pre>\n";
