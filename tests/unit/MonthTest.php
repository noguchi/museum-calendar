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
