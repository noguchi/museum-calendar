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
];
foreach ($events as $event) {
    $lines[] = sprintf(
        'event=[%s] [%s] %s [%s] [%s] [%s]',
        $event['title'],
        $event['time'],
        $event['all_day'] ? 'all-day' : 'timed',
        $event['start'],
        $event['end'],
        $event['fixture_note'] ?? '',
    );
}
echo "<pre class=\"override-today\">\n" . esc_html(implode("\n", $lines)) . "\n</pre>\n";
echo '<pre class="override-today-dump">' . esc_html(print_r($variables, true)) . "</pre>\n";
