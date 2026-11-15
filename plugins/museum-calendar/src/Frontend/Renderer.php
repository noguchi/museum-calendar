<?php

declare(strict_types=1);

namespace MuseumCalendar\Frontend;

use DateTimeImmutable;
use DateTimeZone;
use MuseumCalendar\Calendar\EventList;
use MuseumCalendar\Calendar\EventSource;
use MuseumCalendar\Calendar\Month;
use Throwable;

final class Renderer
{
    public function __construct(private EventSource $source, private bool $demo = false, private string $notice = '')
    {
    }

    public function render(Month $month, DateTimeImmutable $today, bool $showNavigation = true): string
    {
        $events = $this->events($month);
        $todayDate = $today->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d');
        $empty = true;
        $weeks = [];
        foreach ($month->weeks() as $days) {
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
                    'weekday' => $column + 1,
                    'today' => $date === $todayDate,
                    'events' => $events[$date] ?? [],
                ];
                $empty = $empty && !isset($events[$date]);
            }
            $weeks[] = $week;
        }
        $link = static function (int $direction) use ($month, $showNavigation): ?array {
            $adjacent = $showNavigation ? $month->adjacent($direction) : null;
            return $adjacent === null ? null
                : ['month' => $adjacent, 'url' => add_query_arg('museum_month', $adjacent) . '#museum-calendar'];
        };
        return $this->frame('museum-calendar', ' id="museum-calendar" aria-label="月間カレンダー"', self::template('month', [
            'month' => $month->key(),
            'label' => $month->label(),
            'weekdays' => Month::WEEKDAYS,
            'weeks' => $weeks,
            'previous' => $link(-1),
            'next' => $link(1),
            'empty' => $empty,
        ]));
    }

    public function renderToday(DateTimeImmutable $today): string
    {
        $today = $today->setTimezone(new DateTimeZone('Asia/Tokyo'));
        return $this->frame('museum-calendar-today', ' aria-label="本日の予定"', self::template('today', [
            'today' => $today,
            'weekday' => Month::WEEKDAYS[(int) $today->format('N')],
            'events' => $this->events(new Month($today->format('Y-m')))[$today->format('Y-m-d')] ?? [],
        ]));
    }

    private function events(Month $month): array
    {
        return EventList::fromFilter(apply_filters('museum_calendar_events', $this->source->forMonth($month), $month->key()));
    }

    /** The section and both notices stay out of the templates, so a theme template cannot drop them. */
    private function frame(string $class, string $attributes, string $content): string
    {
        $html = '<section class="' . $class . '"' . $attributes . ">\n";
        if ($this->demo) {
            $html .= '<p class="' . $class . '__demo">動作確認用サンプル — 実際の開館情報ではありません</p>' . "\n";
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
            // A static closure without parameters, so the template sees only the extracted variables.
            (static function (): void {
                extract(func_get_arg(1));
                require func_get_arg(0);
            })($file, $variables);
            return (string) ob_get_clean();
        } catch (Throwable $error) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $error;
        }
    }
}
