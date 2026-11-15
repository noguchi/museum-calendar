<?php
/**
 * A theme replaces this file with museum-calendar/today.php. The plugin prints the surrounding section and the notices.
 *
 * @var \DateTimeImmutable $today In Asia/Tokyo
 * @var string $date Today in the WordPress date format
 * @var string $weekday Abbreviated name in the site language. Not printed here. It stays for theme templates.
 * @var list<array{title: string, time: string, all_day: bool, start: string, end: string}> $events
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<p class="museum-calendar-today__date"><time datetime="<?php echo esc_attr($today->format('Y-m-d')); ?>"><?php echo esc_html($date); ?></time></p>
<?php if ($events !== []) : ?>
<ul class="museum-calendar-today__events">
    <?php foreach ($events as $event) : ?>
        <li data-title="<?php echo esc_attr($event['title']); ?>"><strong><?php echo esc_html($event['title']); ?></strong><span><?php echo esc_html($event['time']); ?></span></li>
    <?php endforeach; ?>
</ul>
<?php else : ?>
<p class="museum-calendar-today__empty">本日の予定はありません。</p>
<?php endif; ?>
