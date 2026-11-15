<?php

declare(strict_types=1);

namespace MuseumCalendar\Frontend;

use DateTimeImmutable;
use DateTimeZone;
use MuseumCalendar\Calendar\EventSource;
use MuseumCalendar\Calendar\Month;

final class Renderer
{
    public function __construct(private EventSource $source, private bool $demo = false, private string $notice = '')
    {
    }

    public function render(Month $month, DateTimeImmutable $today, bool $showNavigation = true): string
    {
        $events = $this->source->forMonth($month);
        $todayDate = $today->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d');
        $demo = $this->demo;
        $notice = $this->notice;
        ob_start();
        require dirname(__DIR__, 2) . '/templates/month.php';
        return (string) ob_get_clean();
    }
}
