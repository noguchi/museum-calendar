<?php

declare(strict_types=1);

namespace MuseumCalendar\Calendar;

final class DemoSource implements EventSource
{
    private const CLOSED = ['title' => '休館（サンプル）', 'time' => '終日'];
    private const OPEN = ['title' => '開館（サンプル）', 'time' => '10:30–16:30'];
    private const TOUR = ['title' => 'ツアー（サンプル）', 'time' => '11:00–12:00'];
    private const TEA_ROOM = ['title' => '茶室（サンプル）', 'time' => '12:00–16:00'];

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
