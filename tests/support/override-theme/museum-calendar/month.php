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
    echo 'partial month output';
    ob_start();
    echo 'nested month output';
    throw new RuntimeException('The fixture template failed.');
}
$link = static fn (?array $link): string => $link === null ? 'none' : $link['month'] . ' [' . $link['label'] . '] ' . $link['url'];
$lines = [
    'variables=' . implode(',', array_keys($variables)),
    'this=' . (isset($this) ? 'visible' : 'hidden'),
    'scope=' . $scope,
    'month=' . $month,
    'label=' . $label,
    'weekdays=' . implode(',', array_map(static fn (int $number, string $name): string => $number . $name, array_keys($weekdays), $weekdays)),
    'previous=' . $link($previous),
    'next=' . $link($next),
    'empty=' . ($empty ? 'yes' : 'no'),
    'lang=' . $lang,
    'text=' . implode(' | ', array_map(static fn (string $key, string $value): string => $key . ': ' . $value, array_keys($text), $text)),
    'weeks=' . implode(',', array_map('count', $weeks)),
    'outside=' . count(array_filter(array_merge(...$weeks), 'is_null')),
];
foreach ($weeks as $week) {
    $lines[] = 'row=' . implode(',', array_map(static fn (?array $cell): string => $cell === null ? '-' : (string) $cell['day'], $week));
}
foreach (array_filter(array_merge(...$weeks)) as $cell) {
    $lines[] = sprintf('cell=%s day=%d weekday=%d today=%s', $cell['date'], $cell['day'], $cell['weekday'], $cell['today'] ? 'yes' : 'no');
    foreach ($cell['events'] as $event) {
        $lines[] = sprintf(
            'event=%s [%s] [%s] %s [%s] [%s] [%s]',
            $cell['date'],
            $event['title'],
            $event['time'],
            $event['all_day'] ? 'all-day' : 'timed',
            $event['start'],
            $event['end'],
            $event['fixture_note'] ?? '',
        );
        $lines[] = sprintf('label=%s [%s] [%s] [%s]', $cell['date'], $event['title'], $event['label'], $event['label_lang']);
        $lines[] = sprintf('kind=%s [%s] [%s]', $cell['date'], $event['title'], $event['kind']);
        $lines[] = sprintf('description=%s [%s] %s', $cell['date'], $event['title'], json_encode($event['description'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
echo "<pre class=\"override-month\">\n" . esc_html(implode("\n", $lines)) . "\n</pre>\n";
echo '<pre class="override-month-dump">' . esc_html(print_r($variables, true)) . "</pre>\n";
