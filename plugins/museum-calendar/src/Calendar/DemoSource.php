<?php

declare(strict_types=1);

namespace MuseumCalendar\Calendar;

use MuseumCalendar\Frontend\Language;

final class DemoSource implements EventSource
{
    private const OPEN = ['title' => 'OPEN', 'time' => '10:30–16:30', 'all_day' => false, 'start' => '10:30', 'end' => '16:30'];
    private const TOUR = ['title' => 'ガイドツアー開催日', 'time' => '11:00–12:00', 'all_day' => false, 'start' => '11:00', 'end' => '12:00'];
    private const TEA_ROOM = ['title' => 'お茶室営業日', 'time' => '12:00–16:00', 'all_day' => false, 'start' => '12:00', 'end' => '16:00'];

    public function forMonth(Month $month): array
    {
        $closed = ['title' => 'CLOSE', 'time' => Language::Japanese->text('all_day'), 'all_day' => true, 'start' => '', 'end' => ''];
        // Monday first, the default column order of Month::weeks().
        $samples = [
            [$closed],
            [self::OPEN, self::TOUR],
            [self::OPEN, self::TOUR],
            [self::OPEN, self::TOUR],
            [self::OPEN, self::TOUR],
            [self::OPEN, self::TEA_ROOM],
            [self::OPEN, self::TEA_ROOM],
        ];
        $events = [];
        foreach ($month->weeks() as $week) {
            foreach ($week as $column => $day) {
                if ($day !== null) {
                    $events[sprintf('%s-%02d', $month->key(), $day)] = $samples[$column];
                }
            }
        }
        return $events;
    }
}
