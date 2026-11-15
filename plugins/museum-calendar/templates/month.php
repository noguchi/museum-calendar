<?php
/** @var \MuseumCalendar\Calendar\Month $month */
/** @var array $events */
/** @var string $todayDate */
if (!defined('ABSPATH')) {
    exit;
}
?>
<section class="museum-calendar" id="museum-calendar" aria-label="月間カレンダー">
    <?php if ($demo) : ?><p class="museum-calendar__demo">動作確認用サンプル — 実際の開館情報ではありません</p><?php endif; ?>
    <?php if ($notice !== '') : ?><p class="museum-calendar__notice" role="status"><?php echo esc_html($notice); ?></p><?php endif; ?>
    <?php if ($showNavigation) : ?>
    <nav class="museum-calendar__nav" aria-label="表示月の切り替え">
        <?php foreach ([-1 => '前月', 1 => '翌月'] as $direction => $label) : ?>
            <?php if ($month->adjacent($direction) !== null) : ?>
                <a href="<?php echo esc_url(add_query_arg('museum_month', $month->adjacent($direction)) . '#museum-calendar'); ?>"><?php echo esc_html($label); ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <div class="museum-calendar__scroll" tabindex="0" role="region" aria-label="月間予定表（横にスクロールできます）">
        <table>
            <caption><?php echo esc_html($month->label()); ?></caption>
            <thead><tr>
                <?php foreach ($month::WEEKDAYS as $weekday) : ?>
                    <th scope="col"><?php echo esc_html($weekday); ?></th>
                <?php endforeach; ?>
            </tr></thead>
            <tbody>
                <?php foreach ($month->weeks() as $week) : ?>
                    <tr>
                        <?php foreach ($week as $day) : ?>
                            <?php $date = $day === null ? null : sprintf('%s-%02d', $month->key(), $day); ?>
                            <td<?php echo $date === $todayDate ? ' aria-current="date"' : ''; ?>>
                                <?php if ($date !== null) : ?>
                                    <time datetime="<?php echo esc_attr($date); ?>"><?php echo esc_html((string) $day); ?></time>
                                    <?php if (isset($events[$date])) : ?>
                                        <ul>
                                            <?php foreach ($events[$date] as $event) : ?>
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
    <?php if ($events === []) : ?><p class="museum-calendar__empty">この月の予定はありません。</p><?php endif; ?>
</section>
