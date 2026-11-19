<?php
/**
 * A theme replaces this file with museum-calendar/month.php. The plugin prints the surrounding section and the notices.
 * With lang="en" on the shortcode, "the site language" below is English.
 *
 * @var string $month YYYY-MM
 * @var string $label The month in the WordPress format for monthly archives, in the site language
 * @var array<int, string> $weekdays ISO 8601 weekday number => abbreviated name in the site language, in column order
 * @var list<list<array{date: string, day: int, weekday: int, today: bool, events: list<array{title: string, label: string, label_lang: string, kind: string, time: string, all_day: bool, start: string, end: string}>}|null>> $weeks each week in column order, null is a cell outside the month. title is the registered title and label is the text to print for it. label_lang is 'ja' when label is Japanese text in an English calendar, otherwise ''. kind is the kind of the title, 'other' outside the table
 * @var array{month: string, url: string, label: string}|null $previous label is the month name in the site language
 * @var array{month: string, url: string, label: string}|null $next
 * @var bool $empty
 * @var string $lang 'ja' or 'en', the language of the shortcode
 * @var array{scroll_label: string, empty: string} $text The plugin's own text in that language, not escaped
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<?php if ($previous !== null || $next !== null) : ?>
<nav class="museum-calendar__nav" aria-label="<?php esc_attr_e('Previous and next months'); ?>">
    <?php if ($previous !== null) : ?>
        <a href="<?php echo esc_url($previous['url']); ?>">&laquo; <?php echo esc_html($previous['label']); ?></a>
    <?php endif; ?>
    <?php if ($next !== null) : ?>
        <a href="<?php echo esc_url($next['url']); ?>"><?php echo esc_html($next['label']); ?> &raquo;</a>
    <?php endif; ?>
</nav>
<?php endif; ?>
<div class="museum-calendar__scroll" tabindex="0" role="region" aria-label="<?php echo esc_attr($text['scroll_label']); ?>">
    <table>
        <caption><?php echo esc_html($label); ?></caption>
        <thead><tr>
            <?php foreach ($weekdays as $number => $weekday) : ?>
                <th scope="col" data-weekday="<?php echo esc_attr((string) $number); ?>"><?php echo esc_html($weekday); ?></th>
            <?php endforeach; ?>
        </tr></thead>
        <tbody>
            <?php foreach ($weeks as $week) : ?>
                <tr>
                    <?php foreach ($week as $cell) : ?>
                        <td<?php if ($cell !== null) : ?> data-weekday="<?php echo esc_attr((string) $cell['weekday']); ?>"<?php echo $cell['today'] ? ' aria-current="date"' : ''; ?><?php endif; ?>>
                            <?php if ($cell !== null) : ?>
                                <time datetime="<?php echo esc_attr($cell['date']); ?>"><?php echo esc_html((string) $cell['day']); ?></time>
                                <?php if ($cell['events'] !== []) : ?>
                                    <ul>
                                        <?php foreach ($cell['events'] as $event) : ?>
                                            <li data-title="<?php echo esc_attr($event['title']); ?>" data-kind="<?php echo esc_attr($event['kind']); ?>"><strong<?php if ($event['label_lang'] !== '') : ?> lang="<?php echo esc_attr($event['label_lang']); ?>"<?php endif; ?>><?php echo esc_html($event['label']); ?></strong><span><?php echo esc_html($event['time']); ?></span></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php if ($empty) : ?><p class="museum-calendar__empty"><?php echo esc_html($text['empty']); ?></p><?php endif; ?>
