<?php

declare(strict_types=1);

namespace MuseumCalendar\Frontend;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MuseumCalendar\Calendar\EventList;
use MuseumCalendar\Calendar\EventSource;
use MuseumCalendar\Calendar\Month;
use Throwable;

final class Renderer
{
    /** @param string $notice Text in the language of this renderer. Empty for none. */
    public function __construct(
        private EventSource $source,
        private bool $demo = false,
        private string $notice = '',
        private Language $language = Language::Japanese,
    ) {
    }

    /** @param int $firstWeekday ISO 8601 weekday number of the first column */
    public function render(Month $month, DateTimeImmutable $today, bool $showNavigation = true, int $firstWeekday = 1): string
    {
        $events = $this->events($month);
        $todayDate = $today->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d');
        $empty = true;
        $order = Month::weekdayOrder($firstWeekday);
        $weekdays = [];
        foreach ($order as $weekday) {
            $weekdays[$weekday] = self::weekdayName($weekday);
        }
        $weeks = [];
        foreach ($month->weeks($firstWeekday) as $days) {
            $week = [];
            foreach ($days as $column => $day) {
                if ($day === null) {
                    $week[] = null;
                    continue;
                }
                $date = sprintf('%s-%02d', $month->key(), $day);
                $week[] = [
                    'date' => $date,
                    'day' => $day,
                    'weekday' => $order[$column],
                    'today' => $date === $todayDate,
                    'events' => $events[$date] ?? [],
                ];
                $empty = $empty && !isset($events[$date]);
            }
            $weeks[] = $week;
        }
        $link = static function (int $direction) use ($month, $showNavigation): ?array {
            $adjacent = $showNavigation ? $month->adjacent($direction) : null;
            return $adjacent === null ? null : [
                'month' => $adjacent,
                'url' => add_query_arg('museum_month', $adjacent) . '#museum-calendar',
                'label' => self::monthText('F', $adjacent),
            ];
        };
        return $this->frame('museum-calendar', ' id="museum-calendar"', 'month_label', self::template('month', [
            'month' => $month->key(),
            'label' => self::monthText(_x('F Y', 'monthly archives date format'), $month->key()),
            'weekdays' => $weekdays,
            'weeks' => $weeks,
            'previous' => $link(-1),
            'next' => $link(1),
            'empty' => $empty,
            'lang' => $this->language->value,
            'text' => [
                'scroll_label' => $this->language->text('scroll_label'),
                'empty' => $this->language->text('empty_month'),
            ],
        ]));
    }

    /** @param string $dateFormat PHP date format. wp_date() translates the month and weekday names in it. */
    public function renderToday(DateTimeImmutable $today, string $dateFormat): string
    {
        $tokyo = new DateTimeZone('Asia/Tokyo');
        $today = $today->setTimezone($tokyo);
        $events = $this->events(new Month($today->format('Y-m')))[$today->format('Y-m-d')] ?? [];
        return $this->frame('museum-calendar-today', '', 'today_label', self::template('today', [
            'today' => $today,
            'date' => wp_date($dateFormat, $today->getTimestamp(), $tokyo),
            'weekday' => self::weekdayName((int) $today->format('N')),
            'status' => EventList::status($events),
            'events' => $events,
            'lang' => $this->language->value,
            'text' => ['empty' => $this->language->text('empty_today')],
        ]));
    }

    /** @param string $month YYYY-MM */
    private static function monthText(string $format, string $month): string
    {
        $tokyo = new DateTimeZone('Asia/Tokyo');
        return wp_date($format, (new DateTimeImmutable($month . '-01', $tokyo))->getTimestamp(), $tokyo);
    }

    /** @param int $weekday ISO 8601 weekday number. WordPress numbers the weekdays 0 (Sunday) to 6. */
    private static function weekdayName(int $weekday): string
    {
        $locale = $GLOBALS['wp_locale'];
        return $locale->get_weekday_abbrev($locale->get_weekday($weekday % 7));
    }

    /** The display keys are added after the filter, so a value that a filter put in them is replaced. */
    private function events(Month $month): array
    {
        return array_map(
            fn (array $events): array => array_map($this->language->event(...), $events),
            EventList::forDisplay($this->source, $month),
        );
    }

    /** The section and both notices stay out of the templates, so a theme template cannot drop them. */
    private function frame(string $class, string $attributes, string $label, string $content): string
    {
        $html = '<section class="' . $class . '"' . $attributes . ' aria-label="' . esc_attr($this->language->text($label)) . '"'
            . $this->language->attribute() . ">\n";
        if ($this->demo) {
            $html .= '<p class="' . $class . '__demo">' . esc_html($this->language->text('demo')) . '</p>' . "\n";
        }
        if ($this->notice !== '') {
            $html .= '<p class="' . $class . '__notice" role="status">' . esc_html($this->notice) . '</p>' . "\n";
        }
        return $html . $content . "</section>\n";
    }

    /** A theme file `museum-calendar/{name}.php` replaces the plugin's template. */
    private static function template(string $name, array $variables): string
    {
        $file = locate_template('museum-calendar/' . $name . '.php') ?: dirname(__DIR__, 2) . '/templates/' . $name . '.php';
        $level = ob_get_level();
        ob_start();
        try {
            // No parameters, no object and no class scope, so the template reaches only the extracted variables.
            Closure::bind(static function (): void {
                extract(func_get_arg(1));
                require func_get_arg(0);
            }, null, null)($file, $variables);
            return (string) ob_get_clean();
        } catch (Throwable $error) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $error;
        }
    }
}
