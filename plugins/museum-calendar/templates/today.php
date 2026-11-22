<?php
/**
 * 本日の予定のテンプレート。テーマに museum-calendar/today.php を置くと、このファイルの代わりに使う。
 * 外側の section 要素と案内（前回取得した予定を表示している旨など）はプラグインが出力するので、ここには書かない。
 * ショートコードに lang="en" を付けたときは、以下の「サイトの言語」は英語になる。
 *
 * @var \DateTimeImmutable $today 今日。Asia/Tokyo
 * @var string $date WordPressの日付の書式で表した今日。lang="en" では D, M j の書式
 * @var string $weekday サイトの言語での曜日の略称。このテンプレートは出力しない。テーマのテンプレートで使うために渡している
 * @var array{kind: string, color: string, label: string, label_lang: string, hours: string}|null $status
 *      本日の開館状況。最初の closed の予定、なければ最初の open の予定から作る。どちらもなければ null。
 *      kind: 'open' か 'closed'。
 *      color: その予定の色（#rrggbb）。なければ ''。このテンプレートは --museum-calendar-kind-color に入れる。
 *      label: 表示する文言。label_lang: 英語のページで label が日本語のとき 'ja'、それ以外は ''。
 *      hours: 時刻付きの open の予定ならその時刻、それ以外は ''。
 * @var list<array{title: string, label: string, label_lang: string, kind: string, color: string, time: string, all_day: bool, start: string, end: string, description: string, url: string}> $events
 *      本日の予定。各項目は次のとおり。
 *      title: カレンダーに登録したタイトル。label: 表示する文言。
 *      label_lang: 英語の一覧で label が日本語のとき 'ja'、それ以外は ''。
 *      kind: タイトルから決めた予定の種類。表にないタイトルは 'other'。
 *      color: 表の行の色（#rrggbb）。色のない行と表にないタイトルでは ''。このテンプレートは --museum-calendar-kind-color に入れる。
 *      description: 改行を含むことのあるプレーンテキスト。なければ ''。エスケープしていない。このテンプレートは出力しない。
 *      url: http か https のURL。なければ ''。エスケープしていない。このテンプレートは label をこのURLへのリンクにする。
 * @var string $lang ショートコードの言語。'ja' か 'en'
 * @var array{empty: string} $text その言語でのプラグイン自身の文言。エスケープしていない
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<p class="museum-calendar-today__date"><time datetime="<?php echo esc_attr($today->format('Y-m-d')); ?>"><?php echo esc_html($date); ?></time></p>
<?php if ($status !== null) : ?>
<p class="museum-calendar-today__status" data-kind="<?php echo esc_attr($status['kind']); ?>"<?php if ($status['color'] !== '') : ?> style="--museum-calendar-kind-color: <?php echo esc_attr($status['color']); ?>"<?php endif; ?>><strong<?php if ($status['label_lang'] !== '') : ?> lang="<?php echo esc_attr($status['label_lang']); ?>"<?php endif; ?>><?php echo esc_html($status['label']); ?></strong><?php if ($status['hours'] !== '') : ?><span><?php echo esc_html($status['hours']); ?></span><?php endif; ?></p>
<?php endif; ?>
<?php $listed = array_filter($events, static fn (array $event): bool => !in_array($event['kind'], ['open', 'closed'], true)); ?>
<?php if ($listed !== []) : ?>
<ul class="museum-calendar-today__events">
    <?php foreach ($listed as $event) : ?>
        <li data-title="<?php echo esc_attr($event['title']); ?>" data-kind="<?php echo esc_attr($event['kind']); ?>"<?php if ($event['color'] !== '') : ?> style="--museum-calendar-kind-color: <?php echo esc_attr($event['color']); ?>"<?php endif; ?>><?php if ($event['url'] !== '') : ?><a href="<?php echo esc_url($event['url']); ?>"><?php endif; ?><strong<?php if ($event['label_lang'] !== '') : ?> lang="<?php echo esc_attr($event['label_lang']); ?>"<?php endif; ?>><?php echo esc_html($event['label']); ?></strong><?php if ($event['url'] !== '') : ?></a><?php endif; ?><span><?php echo esc_html($event['time']); ?></span></li>
    <?php endforeach; ?>
</ul>
<?php elseif ($status === null) : ?>
<p class="museum-calendar-today__empty"><?php echo esc_html($text['empty']); ?></p>
<?php endif; ?>
