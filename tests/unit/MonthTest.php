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
            '日曜日に始まる2026-11、月曜始まり' => ['2026-11', 1, [null, null, null, null, null, null, 1], [30, null, null, null, null, null, null], 30],
            '日曜日に始まる2026-11、日曜始まり' => ['2026-11', 7, [1, 2, 3, 4, 5, 6, 7], [29, 30, null, null, null, null, null], 30],
            '日曜日に始まる2026-11、土曜始まり' => ['2026-11', 6, [null, 1, 2, 3, 4, 5, 6], [28, 29, 30, null, null, null, null], 30],
            '月曜日に始まる2026-06、月曜始まり' => ['2026-06', 1, [1, 2, 3, 4, 5, 6, 7], [29, 30, null, null, null, null, null], 30],
            '月曜日に始まる2026-06、日曜始まり' => ['2026-06', 7, [null, 1, 2, 3, 4, 5, 6], [28, 29, 30, null, null, null, null], 30],
            '水曜日に始まる2026-07、月曜始まり' => ['2026-07', 1, [null, null, 1, 2, 3, 4, 5], [27, 28, 29, 30, 31, null, null], 31],
            '水曜日に始まる2026-07、日曜始まり' => ['2026-07', 7, [null, null, null, 1, 2, 3, 4], [26, 27, 28, 29, 30, 31, null], 31],
        ];
    }

    public function testNavigationCrossesYearBoundary(): void
    {
        self::assertSame('2025-12', (new Month('2026-01'))->adjacent(-1));
        self::assertSame('2027-01', (new Month('2026-12'))->adjacent(1));
        self::assertNull((new Month('1900-01'))->adjacent(-1));
        self::assertNull((new Month('2100-12'))->adjacent(1));
    }

    #[DataProvider('navigableLimits')]
    public function testLastNavigableMonthIsTheMonthAfterNextInTokyo(string $today, string $zone, string $last): void
    {
        self::assertSame($last, Month::lastNavigable(new DateTimeImmutable($today, new DateTimeZone($zone)))->key());
    }

    public static function navigableLimits(): array
    {
        return [
            '月の半ば' => ['2026-11-22 12:00', 'Asia/Tokyo', '2027-01'],
            '11月は年をまたぐ' => ['2026-11-01 00:00', 'Asia/Tokyo', '2027-01'],
            '12月は年をまたぐ' => ['2026-12-31 23:59:59', 'Asia/Tokyo', '2027-02'],
            '31日でも月を飛ばさない' => ['2027-01-31 12:00', 'Asia/Tokyo', '2027-03'],
            'うるう年の29日' => ['2028-12-29 12:00', 'Asia/Tokyo', '2029-02'],
            '東京では翌月になっているUTCの夕方' => ['2026-11-30 15:00', 'UTC', '2027-02'],
            '東京ではまだ当月のUTCの夕方' => ['2026-11-30 14:59:59', 'UTC', '2027-01'],
            '東京の0時より前のロサンゼルス' => ['2026-12-31 06:59:59', 'America/Los_Angeles', '2027-02'],
            '東京の0時より後のロサンゼルス' => ['2026-12-31 07:00', 'America/Los_Angeles', '2027-03'],
        ];
    }

    public function testIsAfterComparesWholeMonths(): void
    {
        self::assertTrue((new Month('2027-02'))->isAfter(new Month('2027-01')));
        self::assertTrue((new Month('2027-01'))->isAfter(new Month('2026-12')));
        self::assertFalse((new Month('2027-01'))->isAfter(new Month('2027-01')));
        self::assertFalse((new Month('2026-12'))->isAfter(new Month('2027-01')));
        self::assertFalse((new Month('1900-01'))->isAfter(new Month('2100-12')));
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
