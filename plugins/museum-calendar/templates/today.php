<?php
/**
 * A theme replaces this file with museum-calendar/today.php. The plugin prints the surrounding section and the notices.
 * With lang="en" on the shortcode, "the site language" below is English.
 *
 * @var \DateTimeImmutable $today In Asia/Tokyo
 * @var string $date Today in the WordPress date format. With lang="en", in the format D, M j
 * @var string $weekday Abbreviated name in the site language. Not printed here. It stays for theme templates.
 * @var list<array{title: string, label: string, label_lang: string, kind: string, time: string, all_day: bool, start: string, end: string}> $events title is the registered title and label is the text to print for it. label_lang is 'ja' when label is Japanese text in an English list, otherwise ''. kind is the kind of the title, 'other' outside the table
 * @var string $lang 'ja' or 'en', the language of the shortcode
 * @var array{empty: string} $text The plugin's own text in that language, not escaped
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<p class="museum-calendar-today__date"><time datetime="<?php echo esc_attr($today->format('Y-m-d')); ?>"><?php echo esc_html($date); ?></time></p>
<?php if ($events !== []) : ?>
<ul class="museum-calendar-today__events">
    <?php foreach ($events as $event) : ?>
        <li data-title="<?php echo esc_attr($event['title']); ?>" data-kind="<?php echo esc_attr($event['kind']); ?>"><strong<?php if ($event['label_lang'] !== '') : ?> lang="<?php echo esc_attr($event['label_lang']); ?>"<?php endif; ?>><?php echo esc_html($event['label']); ?></strong><span><?php echo esc_html($event['time']); ?></span></li>
    <?php endforeach; ?>
</ul>
<?php else : ?>
<p class="museum-calendar-today__empty"><?php echo esc_html($text['empty']); ?></p>
<?php endif; ?>
