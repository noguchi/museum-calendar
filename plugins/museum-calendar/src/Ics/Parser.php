<?php
declare(strict_types=1);

namespace MuseumCalendar\Ics;

use DateTimeImmutable;
use DateTimeZone;
use MuseumCalendar\Calendar\EventList;
use MuseumCalendar\Calendar\Month;
use MuseumCalendar\Frontend\Language;
use RuntimeException;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Property\ICalendar\DateTime as DateProperty;
use Sabre\VObject\Reader;
use Sabre\VObject\Settings as VObjectSettings;

final class Parser
{
    public const MAX_BYTES = 2097152;
    public const MAX_DESCRIPTION = 500;

    private function read(string $ics): VCalendar
    {
        $ics = ltrim($ics, "\xEF\xBB\xBF \t\r\n");
        if (strlen($ics) > self::MAX_BYTES || !str_starts_with($ics, 'BEGIN:VCALENDAR')
            || !str_ends_with(rtrim($ics), 'END:VCALENDAR')) {
            throw new RuntimeException('invalid_feed');
        }
        $calendar = Reader::read($ics);
        if (!$calendar instanceof VCalendar || (string) $calendar->VERSION !== '2.0') {
            throw new RuntimeException('invalid_feed');
        }
        $events = $calendar->select('VEVENT');
        if (count($events) > 2000) {
            throw new RuntimeException('too_many_events');
        }
        foreach ($events as $event) {
            if (!isset($event->UID) || (!isset($event->DTSTART) && (string) $event->STATUS !== 'CANCELLED')) {
                throw new RuntimeException('invalid_event');
            }
            if (isset($event->RRULE) && !preg_match('/(?:^|;)FREQ=(DAILY|WEEKLY|MONTHLY|YEARLY)(?:;|$)/', (string) $event->RRULE)) {
                throw new RuntimeException('unsupported_recurrence');
            }
            foreach (['DTSTART', 'DTEND', 'RECURRENCE-ID', 'EXDATE', 'RDATE'] as $property) {
                foreach ($event->select($property) as $dates) {
                    if (!$dates instanceof DateProperty) {
                        throw new RuntimeException('unsupported_date');
                    }
                    $dates->getDateTimes(new DateTimeZone('Asia/Tokyo'));
                }
            }
        }
        return $calendar;
    }

    /**
     * 予定の DESCRIPTION をプレーンテキストにしたものと、予定のURL。
     *
     * 改行は残し、ほかのマークアップは除く。正しいURLだけの最初の行は `url` にして本文から外し、残りを MAX_DESCRIPTION 文字で切って `…` を付ける。
     * URLを先に取り出すので、切る位置より後ろにあるURLも `url` になる。
     * 文字参照はタグを除く前に戻す。こうすると `&lt;script&gt;` のようにエスケープしたタグも除かれ、マークアップとしてテンプレートに届かない。
     * その代わり、`a<b` のようにタグに見えるだけの文字列も一緒に消える。
     *
     * @return array{description: string, url: string}
     */
    public static function description(string $html): array
    {
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1\s*>/is', '', $text) ?? '';
        $text = preg_replace('/<br\b[^>]*>|<\/(?:p|div|li)\s*>/i', "\n", $text) ?? '';
        $text = str_replace(["\r\n", "\r"], "\n", strip_tags($text));
        ['text' => $text, 'url' => $url] = self::link($text);
        return [
            'description' => mb_strlen($text, 'UTF-8') > self::MAX_DESCRIPTION
                ? mb_substr($text, 0, self::MAX_DESCRIPTION, 'UTF-8') . '…'
                : $text,
            'url' => $url,
        ];
    }

    /**
     * プレーンテキストから、URLだけの最初の行を取り出す。ほかの行は残し、文中のURLもそのままにする。
     *
     * @return array{text: string, url: string} text は前後の空白を除いたもの
     */
    private static function link(string $text): array
    {
        $trim = static fn (string $text): string => preg_replace('/^\s+|\s+$/u', '', $text) ?? $text;
        $lines = explode("\n", $text);
        foreach ($lines as $index => $line) {
            if (EventList::isUrl($trim($line))) {
                unset($lines[$index]);
                return ['text' => $trim(implode("\n", $lines)), 'url' => $trim($line)];
            }
        }
        return ['text' => $trim($text), 'url' => ''];
    }

    /** @return array<string, list<array{title: string, time: string, all_day: bool, start: string, end: string, description: string, url: string}>> */
    public function forMonth(string $ics, Month $month): array
    {
        $calendar = $this->read($ics);
        $zone = new DateTimeZone('Asia/Tokyo');
        $floatingZone = isset($calendar->{'X-WR-TIMEZONE'})
            ? new DateTimeZone((string) $calendar->{'X-WR-TIMEZONE'}) : $zone;
        $from = new DateTimeImmutable($month->key() . '-01 00:00:00', $zone);
        $until = $from->modify('+1 month');
        $cancelled = [];
        foreach ($calendar->select('VEVENT') as $event) {
            if ((string) $event->STATUS === 'CANCELLED' && !isset($event->{'RECURRENCE-ID'})) {
                $cancelled[(string) $event->UID] = true;
            }
        }
        foreach ($calendar->select('VEVENT') as $event) {
            if (isset($cancelled[(string) $event->UID])) {
                $calendar->remove($event);
                continue;
            }
            // 取り消した例外の回は RECURRENCE-ID しか持たないことがある。
            // 展開の間も残し、元の回が表示されないようにする。
            if (!isset($event->DTSTART)) {
                $start = clone $event->{'RECURRENCE-ID'};
                $start->name = 'DTSTART';
                $event->add($start);
            }
            foreach ($event->children() as $property) {
                if ($property instanceof DateProperty && $property->hasTime() && $property->isFloating()) {
                    $property->setDateTimes($property->getDateTimes($floatingZone));
                }
            }
            if ($event->DTSTART->hasTime() && !isset($event->DTEND) && !isset($event->DURATION)) {
                $event->add('DTEND', $event->DTSTART->getDateTime($zone)->modify('+1 second'));
                $event->add('X-MUSEUM-POINT', 'TRUE');
            }
        }
        $previousLimit = VObjectSettings::$maxRecurrences;
        try {
            VObjectSettings::$maxRecurrences = 10000;
            $expanded = $calendar->expand($from, $until, $zone);
        } finally {
            VObjectSettings::$maxRecurrences = $previousLimit;
        }
        $result = [];
        $count = 0;
        foreach ($expanded->select('VEVENT') as $event) {
            if ((string) $event->STATUS === 'CANCELLED') {
                continue;
            }
            if (++$count > 5000) {
                throw new RuntimeException('too_many_occurrences');
            }
            $allDay = !$event->DTSTART->hasTime();
            $start = $event->DTSTART->getDateTime($zone)->setTimezone($zone);
            $end = isset($event->DTEND) ? $event->DTEND->getDateTime($zone)->setTimezone($zone)
                : (isset($event->DURATION) ? $start->add(DateTimeParser::parseDuration((string) $event->DURATION))
                    : ($allDay ? $start->modify('+1 day') : $start));
            if ($end < $start) {
                throw new RuntimeException('invalid_duration');
            }
            $point = isset($event->{'X-MUSEUM-POINT'}) || $end == $start;
            ['description' => $description, 'url' => $url] = self::description((string) $event->DESCRIPTION);
            $day = max($start->setTime(0, 0), $from);
            while ($day < $until && ($day < $end || ($point && $day->format('Y-m-d') === $start->format('Y-m-d')))) {
                $date = $day->format('Y-m-d');
                $dayStart = $allDay ? '' : ($start->format('Y-m-d') === $date ? $start->format('H:i') : '00:00');
                $dayEnd = $allDay || $point ? '' : ($end->format('Y-m-d') === $date ? $end->format('H:i') : '24:00');
                $result[$date][] = [
                    'title' => (string) $event->SUMMARY ?: Language::Japanese->text('untitled'),
                    'time' => $allDay ? Language::Japanese->text('all_day') : $dayStart . ($dayEnd === '' ? '' : '–' . $dayEnd),
                    'all_day' => $allDay,
                    'start' => $dayStart,
                    'end' => $dayEnd,
                    'description' => $description,
                    'url' => $url,
                    '_sort' => [$allDay ? 0 : 1, $start->getTimestamp(), (string) $event->SUMMARY],
                ];
                $day = $day->modify('+1 day');
            }
        }
        ksort($result);
        foreach ($result as &$events) {
            usort($events, static fn (array $a, array $b): int => $a['_sort'] <=> $b['_sort']);
            foreach ($events as &$event) {
                unset($event['_sort']);
            }
            unset($event);
        }
        unset($events);
        return $result;
    }
}
