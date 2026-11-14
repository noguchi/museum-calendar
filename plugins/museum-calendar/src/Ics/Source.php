<?php
declare(strict_types=1);

namespace MuseumCalendar\Ics;

use MuseumCalendar\Calendar\EventSource;
use MuseumCalendar\Calendar\Month;

final class Source implements EventSource
{
    public function __construct(private string $body)
    {
    }

    public function forMonth(Month $month): array
    {
        return (new Parser())->forMonth($this->body, $month);
    }
}
