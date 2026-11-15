<?php
/** @var \DateTimeImmutable $today */
/** @var string $weekday */
/** @var list<array{title: string, time: string, all_day: bool, start: string, end: string}> $events */
if (!defined('ABSPATH')) {
    exit;
}
?>
<section class="museum-calendar-today" aria-label="本日の予定">
    <?php if ($demo) : ?><p class="museum-calendar-today__demo">動作確認用サンプル — 実際の開館情報ではありません</p><?php endif; ?>
    <?php if ($notice !== '') : ?><p class="museum-calendar-today__notice" role="status"><?php echo esc_html($notice); ?></p><?php endif; ?>
    <p class="museum-calendar-today__date"><time datetime="<?php echo esc_attr($today->format('Y-m-d')); ?>"><?php echo esc_html($today->format('n/j')); ?></time><span class="museum-calendar-today__weekday"><?php echo esc_html($weekday); ?></span></p>
    <?php if ($events !== []) : ?>
    <ul class="museum-calendar-today__events">
        <?php foreach ($events as $event) : ?>
            <li data-title="<?php echo esc_attr($event['title']); ?>"><strong><?php echo esc_html($event['title']); ?></strong><span><?php echo esc_html($event['time']); ?></span></li>
        <?php endforeach; ?>
    </ul>
    <?php else : ?>
    <p class="museum-calendar-today__empty">本日の予定はありません。</p>
    <?php endif; ?>
</section>
