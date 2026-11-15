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

    public function renderToday(DateTimeImmutable $today): string
    {
        $today = $today->setTimezone(new DateTimeZone('Asia/Tokyo'));
        $events = $this->source->forMonth(new Month($today->format('Y-m')))[$today->format('Y-m-d')] ?? [];
        $weekday = Month::WEEKDAYS[(int) $today->format('N')];
        $demo = $this->demo;
        $notice = $this->notice;
        ob_start();
        require dirname(__DIR__, 2) . '/templates/today.php';
        return (string) ob_get_clean();
    }
}
