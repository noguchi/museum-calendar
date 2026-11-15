<?php

declare(strict_types=1);

namespace MuseumCalendar\Calendar;

final class DemoSource implements EventSource
{
    private const CLOSED = ['title' => 'サンプル休館', 'time' => '終日'];
    private const OPEN = ['title' => 'サンプル開館', 'time' => '10:30–16:30'];
    private const TOUR = ['title' => 'サンプルツアー', 'time' => '11:00–12:00'];
    private const TEA_ROOM = ['title' => 'サンプル茶室', 'time' => '12:00–16:00'];

    /** Monday first, in the column order of Month::weeks(). */
    private const WEEK = [
        [self::CLOSED],
        [self::OPEN, self::TOUR],
        [self::OPEN, self::TOUR],
        [self::OPEN, self::TOUR],
        [self::OPEN, self::TOUR],
        [self::OPEN, self::TEA_ROOM],
        [self::OPEN, self::TEA_ROOM],
    ];

    public function forMonth(Month $month): array
    {
        $events = [];
        foreach ($month->weeks() as $week) {
            foreach ($week as $column => $day) {
                if ($day !== null) {
                    $events[sprintf('%s-%02d', $month->key(), $day)] = self::WEEK[$column];
                }
            }
        }
        return $events;
    }
}
