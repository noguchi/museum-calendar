<?php

declare(strict_types=1);

namespace MuseumCalendar\Calendar;

final class EventList
{
    /**
     * サイトに表示する形にした、1か月の予定。
     *
     * @return array<string, non-empty-list<array{title: string, kind: string, color: string, time: string, all_day: bool, start: string, end: string, description: string, url: string}>>
     */
    public static function forDisplay(EventSource $source, Month $month, KindTable $kinds): array
    {
        return self::classify($source->forMonth($month), $kinds);
    }

    /**
     * 予定ごとに、タイトルを表で引いて `kind` と `color` を付け、`closed` のある日から `open` を除く。
     *
     * 通常の開館は繰り返しの open の予定で登録する。その上に1日だけの closed の予定を重ねたとき、その日の open の予定を削除しなくても closed が勝つようにする。
     * 少なくとも closed の予定は残るので、ここで予定が空になる日はない。
     *
     * @param array<string, non-empty-list<array{title: string, ...}>> $days
     */
    public static function classify(array $days, KindTable $kinds): array
    {
        foreach ($days as $date => $events) {
            foreach ($events as $index => $event) {
                $events[$index]['kind'] = $kinds->kind($event['title']);
                $events[$index]['color'] = $kinds->color($event['title']);
            }
            if (in_array(KindTable::CLOSED, array_column($events, 'kind'), true)) {
                $events = array_values(array_filter($events, static fn (array $event): bool => $event['kind'] !== KindTable::OPEN));
            }
            $days[$date] = $events;
        }
        return $days;
    }

    /**
     * 1日の開館状況。最初の closed の予定、なければ最初の open の予定、どちらもなければ null。
     *
     * @param list<array{kind: string, color: string, label: string, label_lang: string, time: string, all_day: bool, ...}> $events classify() を通し、label を付けた1日分の予定
     * @return array{kind: string, color: string, label: string, label_lang: string, hours: string}|null color と label_lang はその予定の値で、なければ ''。hours は時刻付きの open の予定の時刻で、それ以外は ''
     */
    public static function status(array $events): ?array
    {
        foreach ([KindTable::CLOSED, KindTable::OPEN] as $kind) {
            foreach ($events as $event) {
                if ($event['kind'] === $kind) {
                    return [
                        'kind' => $kind,
                        'color' => $event['color'],
                        'label' => $event['label'],
                        'label_lang' => $event['label_lang'],
                        'hours' => $kind === KindTable::OPEN && !$event['all_day'] ? $event['time'] : '',
                    ];
                }
            }
        }
        return null;
    }

    /** ホストを持ち、空白と制御文字を含まない、http か https の絶対URLか。 */
    public static function isUrl(string $value): bool
    {
        if (preg_match('/^https?:\/\/[^\s\p{C}]+$/iuD', $value) !== 1) {
            return false;
        }
        $host = parse_url($value, PHP_URL_HOST);
        return is_string($host) && $host !== '';
    }
}
