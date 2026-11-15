<?php

declare(strict_types=1);

namespace MuseumCalendar\Frontend;

/**
 * The language of one shortcode, and every text and rule that depends on it.
 *
 * Japanese is the plugin's own text in Japanese, with the WordPress parts in the site language.
 * It is not a forced Japanese locale.
 */
enum Language: string
{
    case Japanese = 'ja';
    case English = 'en';

    /** Arguments fill a row through sprintf(), so a literal percent sign is written as %%. */
    private const TEXT = [
        'month_label' => ['ja' => '月間カレンダー', 'en' => 'Monthly calendar'],
        'today_label' => ['ja' => '本日の予定', 'en' => "Today's schedule"],
        'demo' => ['ja' => '動作確認用サンプル — 実際の開館情報ではありません', 'en' => 'Sample for testing. Not the actual opening information.'],
        'scroll_label' => ['ja' => '月間予定表（横にスクロールできます）', 'en' => 'Monthly schedule (scrolls sideways)'],
        'empty_month' => ['ja' => 'この月の予定はありません。', 'en' => 'No events this month.'],
        'empty_today' => ['ja' => '本日の予定はありません。', 'en' => 'No events today.'],
        'stale' => [
            'ja' => '更新を確認できていないため、前回取得した予定を表示しています。最終取得：%s（日本時間）',
            'en' => 'Updates could not be confirmed, so the previously fetched schedule is shown. Last fetched: %s (Japan time)',
        ],
        'unavailable' => ['ja' => '現在、予定を取得できません。時間をおいてご確認ください。', 'en' => 'The schedule is currently unavailable. Please check again later.'],
        'not_connected' => ['ja' => 'カレンダーの接続が設定されていません。', 'en' => 'The calendar connection is not set up.'],
        'invalid_month' => ['ja' => '年月の指定が正しくありません。', 'en' => 'The month is not valid.'],
        'invalid_language' => ['ja' => '言語の指定が正しくありません。', 'en' => 'The language is not valid.'],
        'month_failed' => ['ja' => 'この月の予定を表示できません。', 'en' => 'The schedule for this month cannot be shown.'],
        'today_failed' => ['ja' => '本日の予定を表示できません。', 'en' => "Today's schedule cannot be shown."],
        'all_day' => ['ja' => '終日', 'en' => 'All day'],
        'untitled' => ['ja' => '（無題）', 'en' => '(Untitled)'],
    ];

    /** The titles the facility registers in its calendar, in Japanese, and the English text of each. */
    private const TITLES = [
        'OPEN' => 'OPEN',
        'CLOSE' => 'CLOSED',
        'お茶室営業日' => 'Tea Room Open',
        'ガイドツアー開催日' => 'Guided Tour',
        '結婚式' => 'Wedding',
        '夜間特別開館' => 'Special Evening Opening',
    ];

    /** @param string $value The `lang` attribute of a shortcode. An omitted attribute is ''. */
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

    /** The text to show for a registered title. Null when this language has no text for it. */
    public function title(string $title): ?string
    {
        if ($this === self::Japanese) {
            return $title;
        }
        $registered = preg_replace('/^\s+|\s+$/u', '', $title) ?? $title;
        if ($registered === self::TEXT['untitled'][self::Japanese->value]) {
            return $this->text('untitled');
        }
        return self::TITLES[$registered] ?? null;
    }

    /**
     * Adds `label` and `label_lang` to an event and puts its all-day time into this language.
     *
     * `title` stays as registered. Filters and theme selectors match on it.
     */
    public function event(array $event): array
    {
        $label = $this->title($event['title']);
        $event['label'] = $label ?? $event['title'];
        $event['label_lang'] = $label === null ? self::Japanese->value : '';
        if ($event['time'] === self::Japanese->text('all_day')) {
            $event['time'] = $this->text('all_day');
        }
        return $event;
    }

    /** @param mixed $option The WordPress `date_format` option, as stored. */
    public function dateFormat(mixed $option): string
    {
        return match ($this) {
            // wp_date() returns an empty string for an empty format, so an unusable option falls back to the Japanese WordPress default.
            self::Japanese => is_string($option) && $option !== '' ? $option : 'Y年n月j日',
            // The option holds one format for the whole site. A Japanese format with English names would read 11月16日(Mon).
            self::English => 'D, M j',
        };
    }

    /** The `lang` attribute of the element that holds the output. Japanese declares none and inherits the page's. */
    public function attribute(): string
    {
        return $this === self::Japanese ? '' : ' lang="' . $this->value . '"';
    }

    /** Runs the rendering with the WordPress month names, weekday names and core strings of this language. */
    public function withLocale(callable $render): string
    {
        $locale = match ($this) {
            self::Japanese => null,
            self::English => 'en_US',
        };
        // switch_to_locale() returns false when the site already uses that locale. Restoring then would pop a switch made by other code.
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
