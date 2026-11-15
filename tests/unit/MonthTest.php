<?php
declare(strict_types=1);

use MuseumCalendar\Calendar\Month;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MonthTest extends TestCase
{
    public function testLeapFebruaryUsesMondayFirstGrid(): void
    {
        $weeks = (new Month('2024-02'))->weeks();
        self::assertSame([null, null, null, 1, 2, 3, 4], $weeks[0]);
        self::assertSame([26, 27, 28, 29, null, null, null], $weeks[4]);
        self::assertCount(5, $weeks);
    }

    public function testNonLeapCenturyHasOnly28Days(): void
    {
        $days = array_filter(array_merge(...(new Month('2100-02'))->weeks()));
        self::assertCount(28, $days);
    }

    public function testSixWeekMonthIncludesEveryDayOnce(): void
    {
        $weeks = (new Month('2026-03'))->weeks();
        self::assertCount(6, $weeks);
        self::assertSame(range(1, 31), array_values(array_filter(array_merge(...$weeks))));
    }

    public function testWeekdayOrderStartsAtTheGivenWeekday(): void
    {
        self::assertSame([1, 2, 3, 4, 5, 6, 7], Month::weekdayOrder(1));
        self::assertSame([4, 5, 6, 7, 1, 2, 3], Month::weekdayOrder(4));
        self::assertSame([7, 1, 2, 3, 4, 5, 6], Month::weekdayOrder(7));
    }

    #[DataProvider('invalidWeekdays')]
    public function testWeekdayOrderRejectsNumbersOutsideTheWeek(int $weekday): void
    {
        $this->expectException(InvalidArgumentException::class);
        Month::weekdayOrder($weekday);
    }

    public static function invalidWeekdays(): array
    {
        return [[0], [8]];
    }

    #[DataProvider('grids')]
    public function testWeeksStartOnTheGivenWeekday(string $month, int $firstWeekday, array $firstWeek, array $lastWeek, int $days): void
    {
        $weeks = (new Month($month))->weeks($firstWeekday);
        self::assertSame($firstWeek, $weeks[0]);
        self::assertSame($lastWeek, $weeks[count($weeks) - 1]);
        foreach ($weeks as $week) {
            self::assertCount(7, $week);
        }
        self::assertSame(range(1, $days), array_values(array_filter(array_merge(...$weeks))));
    }

    public static function grids(): array
    {
        return [
            '2026-11 starts on a Sunday, Monday first' => ['2026-11', 1, [null, null, null, null, null, null, 1], [30, null, null, null, null, null, null], 30],
            '2026-11 starts on a Sunday, Sunday first' => ['2026-11', 7, [1, 2, 3, 4, 5, 6, 7], [29, 30, null, null, null, null, null], 30],
            '2026-11 starts on a Sunday, Saturday first' => ['2026-11', 6, [null, 1, 2, 3, 4, 5, 6], [28, 29, 30, null, null, null, null], 30],
            '2026-06 starts on a Monday, Monday first' => ['2026-06', 1, [1, 2, 3, 4, 5, 6, 7], [29, 30, null, null, null, null, null], 30],
            '2026-06 starts on a Monday, Sunday first' => ['2026-06', 7, [null, 1, 2, 3, 4, 5, 6], [28, 29, 30, null, null, null, null], 30],
            '2026-07 starts on a Wednesday, Monday first' => ['2026-07', 1, [null, null, 1, 2, 3, 4, 5], [27, 28, 29, 30, 31, null, null], 31],
            '2026-07 starts on a Wednesday, Sunday first' => ['2026-07', 7, [null, null, null, 1, 2, 3, 4], [26, 27, 28, 29, 30, 31, null], 31],
        ];
    }

    public function testNavigationCrossesYearBoundary(): void
    {
        self::assertSame('2025-12', (new Month('2026-01'))->adjacent(-1));
        self::assertSame('2027-01', (new Month('2026-12'))->adjacent(1));
        self::assertNull((new Month('1900-01'))->adjacent(-1));
        self::assertNull((new Month('2100-12'))->adjacent(1));
    }

    #[DataProvider('invalidMonths')]
    public function testRejectsInvalidInput(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Month($value);
    }

    public static function invalidMonths(): array
    {
        return array_map(static fn ($value) => [$value], ['', '2026-00', '2026-13', '2026-1', '2026-01-01', '2026-01<script>', "2026-01\n", '1899-12', '2101-01']);
    }
}
