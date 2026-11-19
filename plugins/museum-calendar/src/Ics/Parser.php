<?php
declare(strict_types=1);

namespace MuseumCalendar\Ics;

use DateTimeImmutable;
use DateTimeZone;
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

    public function validate(string $ics): void
    {
        $this->read($ics);
    }

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
     * The DESCRIPTION of an event as plain text: line breaks kept, other markup removed, at most MAX_DESCRIPTION characters and `…`.
     *
     * Character references are decoded before the tags are removed, so that an escaped tag such as `&lt;script&gt;`
     * is removed too instead of reaching a template as markup. Text that only looks like a tag, such as `a<b`, is lost with it.
     */
    public static function description(string $html): string
    {
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1\s*>/is', '', $text) ?? '';
        $text = preg_replace('/<br\b[^>]*>|<\/(?:p|div|li)\s*>/i', "\n", $text) ?? '';
        $text = str_replace(["\r\n", "\r"], "\n", strip_tags($text));
        $text = preg_replace('/^\s+|\s+$/u', '', $text) ?? $text;
        return mb_strlen($text, 'UTF-8') > self::MAX_DESCRIPTION
            ? mb_substr($text, 0, self::MAX_DESCRIPTION, 'UTF-8') . '…'
            : $text;
    }

    /** @return array<string, list<array{title: string, time: string, all_day: bool, start: string, end: string, description: string}>> */
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
            // Cancelled exceptions may contain only RECURRENCE-ID. Keep them
            // during expansion so that the original occurrence is suppressed.
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
            $description = self::description((string) $event->DESCRIPTION);
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
