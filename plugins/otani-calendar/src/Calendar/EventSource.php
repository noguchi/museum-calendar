<?php

declare(strict_types=1);

namespace OtaniCalendar\Calendar;

interface EventSource
{
    /** @return array<string, list<array{title: string, time: string}>> Events by YYYY-MM-DD. */
    public function forMonth(Month $month): array;
}
