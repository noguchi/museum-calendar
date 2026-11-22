<?php
/**
 * 月間カレンダーのテンプレート。テーマに museum-calendar/month.php を置くと、このファイルの代わりに使う。
 * 外側の section 要素と案内（前回取得した予定を表示している旨など）はプラグインが出力するので、ここには書かない。
 * ショートコードに lang="en" を付けたときは、以下の「サイトの言語」は英語になる。
 *
 * @var string $month 表示する月。YYYY-MM
 * @var string $label 月の表示。WordPressの月別アーカイブの書式で、サイトの言語
 * @var array<int, string> $weekdays 列の順に並べた、ISO 8601 の曜日番号 => サイトの言語での曜日の略称
 * @var list<list<array{date: string, day: int, weekday: int, today: bool, events: list<array{title: string, label: string, label_lang: string, kind: string, color: string, time: string, all_day: bool, start: string, end: string, description: string, url: string}>}|null>> $weeks
 *      週ごとに、列の順に並べた日。null はその月に含まれないマス。events の各項目は次のとおり。
 *      title: カレンダーに登録したタイトル。label: 表示する文言。
 *      label_lang: 英語のカレンダーで label が日本語のとき 'ja'、それ以外は ''。
 *      kind: タイトルから決めた予定の種類。表にないタイトルは 'other'。
 *      color: 表の行の色（#rrggbb）。色のない行と表にないタイトルでは ''。このテンプレートは --museum-calendar-kind-color に入れる。
 *      description: 改行を含むことのあるプレーンテキスト。なければ ''。エスケープしていない。このテンプレートは出力しない。
 *      url: http か https のURL。なければ ''。エスケープしていない。このテンプレートは label をこのURLへのリンクにする。
 * @var array{month: string, url: string, label: string}|null $previous 前の月へのリンク。ショートコードに month を指定したときは null。label はサイトの言語での月名
 * @var array{month: string, url: string, label: string}|null $next 次の月へのリンク。month を指定したときと、訪問者が開ける最後の月（翌々月）では null
 * @var bool $empty その月に予定が1つもなければ true
 * @var string $lang ショートコードの言語。'ja' か 'en'
 * @var array{scroll_label: string, empty: string} $text その言語でのプラグイン自身の文言。エスケープしていない
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
                                            <li data-title="<?php echo esc_attr($event['title']); ?>" data-kind="<?php echo esc_attr($event['kind']); ?>"<?php if ($event['color'] !== '') : ?> style="--museum-calendar-kind-color: <?php echo esc_attr($event['color']); ?>"<?php endif; ?>><?php if ($event['url'] !== '') : ?><a href="<?php echo esc_url($event['url']); ?>"><?php endif; ?><strong<?php if ($event['label_lang'] !== '') : ?> lang="<?php echo esc_attr($event['label_lang']); ?>"<?php endif; ?>><?php echo esc_html($event['label']); ?></strong><?php if ($event['url'] !== '') : ?></a><?php endif; ?><span><?php echo esc_html($event['time']); ?></span></li>
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
