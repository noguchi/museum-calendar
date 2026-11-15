<?php

declare(strict_types=1);

namespace MuseumCalendar\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class Month
{
    /** Keyed by ISO 8601 weekday number, so the order is Monday first. */
    public const WEEKDAYS = [1 => '月', 2 => '火', 3 => '水', 4 => '木', 5 => '金', 6 => '土', 7 => '日'];

    private DateTimeImmutable $first;

    public function __construct(string $value)
    {
        if (!preg_match('/^(19\d{2}|20\d{2}|2100)-(0[1-9]|1[0-2])$/D', $value)) {
            throw new InvalidArgumentException('Expected a month between 1900-01 and 2100-12.');
        }
        $this->first = new DateTimeImmutable($value . '-01', new DateTimeZone('Asia/Tokyo'));
    }

    public function key(): string
    {
        return $this->first->format('Y-m');
    }

    public function label(): string
    {
        return $this->first->format('Y年n月');
    }

    public function adjacent(int $direction): ?string
    {
        $value = $this->first->modify($direction < 0 ? '-1 month' : '+1 month')->format('Y-m');
        return $value >= '1900-01' && $value <= '2100-12' ? $value : null;
    }

    /** @return list<list<int|null>> Monday-first weeks, without adjacent-month dates. */
    public function weeks(): array
    {
        $cells = array_fill(0, (int) $this->first->format('N') - 1, null);
        for ($day = 1; $day <= (int) $this->first->format('t'); $day++) {
            $cells[] = $day;
        }
        while (count($cells) % 7 !== 0) {
            $cells[] = null;
        }
        return array_chunk($cells, 7);
    }
}
