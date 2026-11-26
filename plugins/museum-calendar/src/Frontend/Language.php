<?php

declare(strict_types=1);

namespace MuseumCalendar\Frontend;

use MuseumCalendar\Calendar\KindTable;

/**
 * ショートコード1つ分の言語と、言語で変わる文言と規則。
 *
 * Japanese は、プラグイン自身の文言を日本語で出し、WordPress由来の部分はサイトの言語で出す。
 * ロケールを日本語に強制するわけではない。
 */
enum Language: string
{
    case Japanese = 'ja';
    case English = 'en';

    /** 引数は sprintf() で埋め込むので、パーセント記号そのものは %% と書く。 */
    private const TEXT = [
        'month_label' => ['ja' => '月間カレンダー', 'en' => 'Monthly calendar'],
        'today_label' => ['ja' => '本日の予定', 'en' => "Today's schedule"],
        'scroll_label' => ['ja' => '月間予定表（横にスクロールできます）', 'en' => 'Monthly schedule (scrolls sideways)'],
        'empty_month' => ['ja' => 'この月の予定はありません。', 'en' => 'No events this month.'],
        'empty_today' => ['ja' => '本日の開館情報はありません。', 'en' => 'No opening information today.'],
        'stale' => [
            'ja' => '更新を確認できていないため、前回取得した予定を表示しています。最終取得：%s（日本時間）',
            'en' => 'Updates could not be confirmed, so the previously fetched schedule is shown. Last fetched: %s (Japan time)',
        ],
        'unavailable' => ['ja' => '現在、予定を取得できません。時間をおいてご確認ください。', 'en' => 'The schedule is currently unavailable. Please check again later.'],
        'not_connected' => ['ja' => 'カレンダーの接続が設定されていません。', 'en' => 'The calendar connection is not set up.'],
        'invalid_month' => ['ja' => '年月の指定が正しくありません。', 'en' => 'The month is not valid.'],
        'future_month' => ['ja' => 'この月の予定はまだ表示できません。', 'en' => 'This month cannot be shown yet.'],
        'invalid_language' => ['ja' => '言語の指定が正しくありません。', 'en' => 'The language is not valid.'],
        'month_failed' => ['ja' => 'この月の予定を表示できません。', 'en' => 'The schedule for this month cannot be shown.'],
        'today_failed' => ['ja' => '本日の予定を表示できません。', 'en' => "Today's schedule cannot be shown."],
        'all_day' => ['ja' => '終日', 'en' => 'All day'],
        'untitled' => ['ja' => '（無題）', 'en' => '(Untitled)'],
    ];

    /** @param string $value ショートコードの `lang` 属性。省略したときは ''。 */
    public static function fromAttribute(string $value): ?self
    {
        return match ($value) {
            '' => self::Japanese,
            'en' => self::English,
            default => null,
        };
    }

    public function text(string $key, string ...$arguments): string
    {
        return sprintf(self::TEXT[$key][$this->value], ...$arguments);
    }

    /** 登録したタイトルに対して表示する文言。この言語での文言がなければ null。 */
    public function title(string $title, KindTable $kinds): ?string
    {
        if ($this === self::Japanese) {
            return $title;
        }
        if (KindTable::trimmed($title) === self::TEXT['untitled'][self::Japanese->value]) {
            return $this->text('untitled');
        }
        return $kinds->english($title);
    }

    /**
     * 予定に `label` と `label_lang` を付け、終日の表示をこの言語にする。
     *
     * `title` は登録したまま残す。テーマのセレクターがこの値に一致させるため。
     */
    public function event(array $event, KindTable $kinds): array
    {
        $label = $this->title($event['title'], $kinds);
        $event['label'] = $label ?? $event['title'];
        $event['label_lang'] = $label === null ? self::Japanese->value : '';
        if ($event['time'] === self::Japanese->text('all_day')) {
            $event['time'] = $this->text('all_day');
        }
        return $event;
    }

    /** @param mixed $option WordPressの `date_format` オプション。保存された値のまま。 */
    public function dateFormat(mixed $option): string
    {
        return match ($this) {
            // wp_date() は空の書式に空文字を返すので、使えない値なら日本語版WordPressの既定の書式にする。
            self::Japanese => is_string($option) && $option !== '' ? $option : 'Y年n月j日',
            // オプションはサイト全体で1つの書式なので使わない。日本語の書式に英語の名前を入れると 11月16日(Mon) のようになる。
            self::English => 'D, M j',
        };
    }

    /** 出力を囲む要素の `lang` 属性。Japanese では付けず、ページの値を継承する。 */
    public function attribute(): string
    {
        return $this === self::Japanese ? '' : ' lang="' . $this->value . '"';
    }

    /** WordPressの月名、曜日名、本体の文言をこの言語にして描画する。 */
    public function withLocale(callable $render): string
    {
        $locale = match ($this) {
            self::Japanese => null,
            self::English => 'en_US',
        };
        // サイトがすでにそのロケールなら switch_to_locale() は false を返す。そのとき戻すと、ほかのコードが行った切り替えを取り消してしまう。
        $switched = $locale !== null && switch_to_locale($locale);
        try {
            return $render();
        } finally {
            if ($switched) {
                restore_previous_locale();
            }
        }
    }
}
