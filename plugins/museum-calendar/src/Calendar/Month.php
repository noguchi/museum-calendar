<?php

declare(strict_types=1);

namespace MuseumCalendar\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class Month
{
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

    /** 訪問者が月の移動で開ける最後の月。Asia/Tokyo での今日の翌々月。 */
    public static function lastNavigable(DateTimeImmutable $today): self
    {
        // 1日から数え、29日から31日でも月を足したときにさらに先の月へずれないようにする。
        $first = $today->setTimezone(new DateTimeZone('Asia/Tokyo'))->modify('first day of this month');
        return new self($first->modify('+2 months')->format('Y-m'));
    }

    public function isAfter(self $other): bool
    {
        return $this->first > $other->first;
    }

    public function adjacent(int $direction): ?string
    {
        $value = $this->first->modify($direction < 0 ? '-1 month' : '+1 month')->format('Y-m');
        return $value >= '1900-01' && $value <= '2100-12' ? $value : null;
    }

    /** @return list<int> 列の順に並べた、ISO 8601 の7つの曜日番号。 */
    public static function weekdayOrder(int $firstWeekday): array
    {
        if ($firstWeekday < 1 || $firstWeekday > 7) {
            throw new InvalidArgumentException('Expected an ISO 8601 weekday number between 1 and 7.');
        }
        return array_map(static fn (int $column): int => ($firstWeekday - 1 + $column) % 7 + 1, range(0, 6));
    }

    /** @return list<list<int|null>> weekdayOrder() の列の順に並べた週。前後の月の日付は入れない。 */
    public function weeks(int $firstWeekday = 1): array
    {
        $cells = array_fill(0, array_search((int) $this->first->format('N'), self::weekdayOrder($firstWeekday), true), null);
        for ($day = 1; $day <= (int) $this->first->format('t'); $day++) {
            $cells[] = $day;
        }
        while (count($cells) % 7 !== 0) {
            $cells[] = null;
        }
        return array_chunk($cells, 7);
    }
}
