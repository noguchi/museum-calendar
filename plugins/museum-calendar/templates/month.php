<?php
/**
 * A theme replaces this file with museum-calendar/month.php. The plugin prints the surrounding section and the notices.
 *
 * @var string $month YYYY-MM
 * @var string $label
 * @var array<int, string> $weekdays ISO 8601 weekday number => label, in column order
 * @var list<list<array{date: string, day: int, weekday: int, today: bool, events: list<array{title: string, time: string, all_day: bool, start: string, end: string}>}|null>> $weeks each week in column order, null is a cell outside the month
 * @var array{month: string, url: string}|null $previous
 * @var array{month: string, url: string}|null $next
 * @var bool $empty
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<?php if ($previous !== null || $next !== null) : ?>
<nav class="museum-calendar__nav" aria-label="表示月の切り替え">
    <?php foreach (['前月' => $previous, '翌月' => $next] as $text => $link) : ?>
        <?php if ($link !== null) : ?>
            <a href="<?php echo esc_url($link['url']); ?>"><?php echo esc_html($text); ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
<div class="museum-calendar__scroll" tabindex="0" role="region" aria-label="月間予定表（横にスクロールできます）">
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
                                            <li data-title="<?php echo esc_attr($event['title']); ?>"><strong><?php echo esc_html($event['title']); ?></strong><span><?php echo esc_html($event['time']); ?></span></li>
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
<?php if ($empty) : ?><p class="museum-calendar__empty">この月の予定はありません。</p><?php endif; ?>
