<?php

declare(strict_types=1);

namespace MuseumCalendar\Calendar;

use UnexpectedValueException;

final class EventList
{
    private const DEFAULTS = ['time' => '', 'all_day' => false, 'start' => '', 'end' => ''];

    /** The events of a month as the site displays them, after the `museum_calendar_events` filter. */
    public static function forDisplay(EventSource $source, Month $month): array
    {
        return self::fromFilter(apply_filters('museum_calendar_events', $source->forMonth($month), $month->key()));
    }

    /**
     * Checks what a `museum_calendar_events` callback returned.
     *
     * An invalid value throws. Showing no events instead would tell visitors that the month is empty.
     * The keys of a day are ignored, because array_filter() leaves gaps when a callback removes an event.
     *
     * @return array<string, non-empty-list<array{title: string, time: string, all_day: bool, start: string, end: string, ...}>>
     */
    public static function fromFilter(mixed $value): array
    {
        if (!is_array($value)) {
            throw new UnexpectedValueException('Events must be an array keyed by date.');
        }
        $days = [];
        foreach ($value as $date => $events) {
            if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
                throw new UnexpectedValueException('Each key must be a YYYY-MM-DD date.');
            }
            if (!is_array($events)) {
                throw new UnexpectedValueException("The events of {$date} must be an array.");
            }
            foreach ($events as $event) {
                if (!is_array($event) || !is_string($event['title'] ?? null)) {
                    throw new UnexpectedValueException("An event of {$date} has no title.");
                }
                $event += self::DEFAULTS;
                foreach (self::DEFAULTS as $key => $default) {
                    if (gettype($event[$key]) !== gettype($default)) {
                        throw new UnexpectedValueException("An event of {$date} has an invalid {$key}.");
                    }
                }
                $days[$date][] = $event;
            }
        }
        return $days;
    }
}
