<?php

declare(strict_types=1);

namespace MuseumCalendar\Calendar;

interface EventSource
{
    /**
     * Events by YYYY-MM-DD. `start` and `end` are the part of the event inside that day, as H:i.
     * Both are '' for an all-day event, and `end` is '' for an event without an end time.
     *
     * @return array<string, list<array{title: string, time: string, all_day: bool, start: string, end: string}>>
     */
    public function forMonth(Month $month): array;
}
