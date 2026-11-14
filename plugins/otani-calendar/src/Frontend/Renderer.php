<?php

declare(strict_types=1);

namespace OtaniCalendar\Frontend;

use OtaniCalendar\Calendar\EventSource;
use OtaniCalendar\Calendar\Month;

final class Renderer
{
    public function __construct(private EventSource $source, private bool $demo = false, private string $notice = '')
    {
    }

    public function render(Month $month, bool $showNavigation = true): string
    {
        $events = $this->source->forMonth($month);
        $demo = $this->demo;
        $notice = $this->notice;
        ob_start();
        require dirname(__DIR__, 2) . '/templates/month.php';
        return (string) ob_get_clean();
    }
}
