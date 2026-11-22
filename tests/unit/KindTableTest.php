<?php
declare(strict_types=1);

use MuseumCalendar\Calendar\KindTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KindTableTest extends TestCase
{
    private static function row(string $title, string $kind, string $english = '', string $color = ''): array
    {
        return ['title' => $title, 'kind' => $kind, 'english' => $english, 'color' => $color];
    }

    #[DataProvider('rows')]
    public function testEachDefaultTitleHasItsKindAndEnglishText(string $title, string $kind, string $english): void
    {
        self::assertSame($kind, KindTable::defaults()->kind($title));
        self::assertSame($english, KindTable::defaults()->english($title));
    }

    public static function rows(): array
    {
        return [
            ['開館日', 'open', 'OPEN'],
            ['休館日', 'closed', 'CLOSED'],
            ['お茶室営業日', 'tea', 'Tea Room Open'],
            ['ガイドツアー開催日', 'tour', 'Guided Tour'],
            ['結婚式', 'wedding', 'Wedding'],
            ['夜間特別開館', 'evening', 'Special Evening Opening'],
            ['コンサート', 'concert', 'Concert'],
            ['イベント', 'event', 'Event'],
        ];
    }

    public function testTheDefaultRowsKeepTheirOrder(): void
    {
        self::assertSame(KindTable::DEFAULTS, KindTable::defaults()->rows());
        self::assertSame(array_column(self::rows(), 0), array_column(KindTable::defaults()->rows(), 'title'));
    }

    public function testTheSpacesAroundATitleAreIgnored(): void
    {
        $table = KindTable::defaults();
        self::assertSame('tea', $table->kind("  お茶室営業日\t\n"));
        self::assertSame('wedding', $table->kind('　結婚式　'));
        self::assertSame('Concert', $table->english(' 　コンサート　 '));
    }

    public function testATitleIsCaseSensitive(): void
    {
        $table = KindTable::fromRows([self::row('OPEN', 'open', 'OPEN')]);
        self::assertSame('open', $table->kind('OPEN'));
        self::assertSame(KindTable::OTHER, $table->kind('open'));
        self::assertSame(KindTable::OTHER, $table->kind('Open'));
    }

    #[DataProvider('otherTitles')]
    public function testAnyOtherTitleIsOtherWithoutEnglishText(string $title): void
    {
        self::assertSame(KindTable::OTHER, KindTable::defaults()->kind($title));
        self::assertNull(KindTable::defaults()->english($title));
    }

    public static function otherTitles(): array
    {
        return [
            'not registered' => ['臨時休館'],
            'longer than a row' => ['お茶室営業日（午後）'],
            'part of a row' => ['お茶室'],
            'a row inside a longer title' => ['アフタヌーンコンサート'],
            'a space inside' => ['ガイド ツアー開催日'],
            'two rows' => ['開館日 休館日'],
            'the English text of the open row' => ['OPEN'],
            'an English word for closed' => ['CLOSE'],
            'the kind of a row' => ['open'],
            'a full-width space inside' => ['休館　日'],
            'an English text of the table' => ['Guided Tour'],
            'a kind' => ['tour'],
            'untitled' => ['（無題）'],
            'empty' => [''],
            'invalid UTF-8' => ["\xFF結婚式"],
        ];
    }

    public function testTrimmedDropsOnlyTheSpacesAroundATitle(): void
    {
        self::assertSame('臨時休館', KindTable::trimmed(" \t臨時休館\n"));
        self::assertSame('臨時休館', KindTable::trimmed('　臨時休館　'));
        self::assertSame('臨時 休館　午後', KindTable::trimmed(' 臨時 休館　午後 '));
        self::assertSame('', KindTable::trimmed('   '));
    }

    public function testRowsAreTrimmedAndKeepTheirOrder(): void
    {
        $table = KindTable::fromRows([self::row('　臨時休館 ', 'closed', ' Closed '), self::row('OPEN', 'open', 'OPEN')]);
        self::assertSame([self::row('臨時休館', 'closed', 'Closed'), self::row('OPEN', 'open', 'OPEN')], $table->rows());
        self::assertSame('closed', $table->kind('臨時休館'));
        self::assertSame('Closed', $table->english(' 臨時休館'));
    }

    public function testARowWithoutTitleIsDropped(): void
    {
        $table = KindTable::fromRows([
            self::row('', '', ''),
            self::row('　 ', 'not a kind!', str_repeat('x', 500)),
            self::row('CLOSE', 'closed', 'CLOSED'),
            self::row('', 'other', "line\nbreak"),
        ]);
        self::assertSame([self::row('CLOSE', 'closed', 'CLOSED')], $table->rows());
    }

    public function testAnEmptyTableMakesEveryTitleOther(): void
    {
        $table = KindTable::fromRows([]);
        self::assertSame([], $table->rows());
        self::assertSame(KindTable::OTHER, $table->kind('OPEN'));
        self::assertNull($table->english('OPEN'));
        self::assertSame([], KindTable::fromRows([self::row(' ', 'open')])->rows());
    }

    public function testSeveralTitlesShareAKind(): void
    {
        $table = KindTable::fromRows([...KindTable::DEFAULTS, self::row('臨時休館', 'closed', 'Closed')]);
        self::assertSame('closed', $table->kind('休館日'));
        self::assertSame('closed', $table->kind('臨時休館'));
        self::assertSame('Closed', $table->english('臨時休館'));
    }

    public function testANewKindIsAllowed(): void
    {
        $table = KindTable::fromRows([self::row('ワークショップ', 'workshop', 'Workshop'), self::row('茶会', 'tea-2', 'Tea Ceremony'), self::row('講座', 'a')]);
        self::assertSame('workshop', $table->kind('ワークショップ'));
        self::assertSame('Workshop', $table->english('ワークショップ'));
        self::assertSame('tea-2', $table->kind('茶会'));
        self::assertSame('a', $table->kind('講座'));
    }

    public function testAnEmptyEnglishTextHasNoTranslation(): void
    {
        $table = KindTable::fromRows([self::row('ワークショップ', 'workshop', '  ')]);
        self::assertSame('workshop', $table->kind('ワークショップ'));
        self::assertNull($table->english('ワークショップ'));
        self::assertSame([self::row('ワークショップ', 'workshop', '')], $table->rows());
    }

    public function testTheDefaultTableHasTheBackgroundsOfTheFacilitySite(): void
    {
        self::assertSame(
            ['開館日' => '#ffebee', '休館日' => '#f5f5f5', 'お茶室営業日' => '#e8f5e9', 'ガイドツアー開催日' => '#e3f2fd', '結婚式' => '#fff0fc', '夜間特別開館' => '#fff9f4', 'コンサート' => '#f5f5f5', 'イベント' => '#f5f5f5'],
            array_column(KindTable::DEFAULTS, 'color', 'title'),
        );
        self::assertSame('#e3f2fd', KindTable::defaults()->color(' ガイドツアー開催日 '));
        self::assertSame('', KindTable::defaults()->color('臨時休館'));
    }

    public function testAColorIsTrimmedAndStoredInLowerCase(): void
    {
        $table = KindTable::fromRows([
            self::row('ワークショップ', 'workshop', '', ' #E26B6B　'),
            self::row('講座', 'lecture', '', '#2f6F4f'),
            self::row('茶会', 'tea', '', '  '),
        ]);
        self::assertSame(['#e26b6b', '#2f6f4f', ''], array_column($table->rows(), 'color'));
        self::assertSame('#e26b6b', $table->color(' ワークショップ '));
        self::assertSame('#2f6f4f', $table->color('講座'));
        self::assertSame('', $table->color('茶会'));
        self::assertSame('', $table->color('臨時休館'));
    }

    public function testARowWithoutTitleIgnoresItsColor(): void
    {
        self::assertSame([], KindTable::fromRows([self::row('', '', '', 'red')])->rows());
    }

    public function testTheLimitsAreInclusive(): void
    {
        $long = str_repeat('あ', KindTable::MAX_LENGTH);
        self::assertSame($long, KindTable::fromRows([self::row($long, 'event', $long)])->english($long));
        self::assertSame('x' . str_repeat('9', 39), KindTable::fromRows([self::row('a', 'x' . str_repeat('9', 39))])->kind('a'));
        $rows = array_map(static fn (int $number): array => self::row("催し{$number}", 'event'), range(1, KindTable::MAX_ROWS));
        self::assertCount(KindTable::MAX_ROWS, KindTable::fromRows([...$rows, self::row('', '')])->rows());
    }

    #[DataProvider('invalidTables')]
    public function testRejectsAnInvalidTable(mixed $rows, string $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($code);
        KindTable::fromRows($rows);
    }

    public static function invalidTables(): array
    {
        $row = static fn (string $title, string $kind, string $english = '', string $color = ''): array => ['title' => $title, 'kind' => $kind, 'english' => $english, 'color' => $color];
        $tooMany = array_map(static fn (int $number): array => $row("催し{$number}", 'event'), range(1, KindTable::MAX_ROWS + 1));
        return [
            'not an array' => ['OPEN', 'invalid_rows'],
            'null' => [null, 'invalid_rows'],
            'keyed by title' => [['OPEN' => $row('OPEN', 'open')], 'invalid_rows'],
            'a row is a string' => [['OPEN'], 'invalid_rows'],
            'a row without kind' => [[['title' => 'OPEN', 'english' => 'OPEN']], 'invalid_rows'],
            'a row without english' => [[['title' => 'OPEN', 'kind' => 'open']], 'invalid_rows'],
            'a row without color' => [[['title' => 'OPEN', 'kind' => 'open', 'english' => '']], 'invalid_rows'],
            'a row without color and title' => [[['title' => '', 'kind' => '', 'english' => '']], 'invalid_rows'],
            'a title that is not a string' => [[['title' => 1, 'kind' => 'open', 'english' => '']], 'invalid_rows'],
            'a kind that is null' => [[['title' => 'OPEN', 'kind' => null, 'english' => '']], 'invalid_rows'],
            'a color that is null' => [[['title' => 'OPEN', 'kind' => 'open', 'english' => '', 'color' => null]], 'invalid_rows'],
            'a color that is an array' => [[['title' => 'OPEN', 'kind' => 'open', 'english' => '', 'color' => ['#ffffff']]], 'invalid_rows'],
            'a color name' => [[$row('OPEN', 'open', '', 'red')], 'invalid_color'],
            'a short hex color' => [[$row('OPEN', 'open', '', '#fff')], 'invalid_color'],
            'a hex color with alpha' => [[$row('OPEN', 'open', '', '#ffffff80')], 'invalid_color'],
            'a hex color without #' => [[$row('OPEN', 'open', '', 'ffffff')], 'invalid_color'],
            'a non-hex digit' => [[$row('OPEN', 'open', '', '#12345g')], 'invalid_color'],
            'a function' => [[$row('OPEN', 'open', '', 'rgb(0, 0, 0)')], 'invalid_color'],
            'a color with a declaration after it' => [[$row('OPEN', 'open', '', '#ffffff;color:red')], 'invalid_color'],
            'a line break inside a color' => [[$row('OPEN', 'open', '', "#ff\nffff")], 'invalid_color'],
            'a duplicate title' => [[$row('OPEN', 'open'), $row('CLOSE', 'closed'), $row(' OPEN　', 'event')], 'duplicate_title'],
            'an empty kind' => [[$row('OPEN', '')], 'invalid_kind'],
            'other' => [[$row('臨時休館', 'other')], 'invalid_kind'],
            'upper case' => [[$row('OPEN', 'Open')], 'invalid_kind'],
            'a leading digit' => [[$row('OPEN', '1st')], 'invalid_kind'],
            'a leading hyphen' => [[$row('OPEN', '-open')], 'invalid_kind'],
            'a space' => [[$row('OPEN', 'open ')], 'invalid_kind'],
            'an underscore' => [[$row('OPEN', 'open_day')], 'invalid_kind'],
            'a quote' => [[$row('OPEN', 'open"')], 'invalid_kind'],
            'a trailing newline' => [[$row('OPEN', "open\n")], 'invalid_kind'],
            'a kind of 41 characters' => [[$row('OPEN', 'x' . str_repeat('9', 40))], 'invalid_kind'],
            'a line break in a title' => [[$row("臨時\n休館", 'closed')], 'invalid_title'],
            'a tab in a title' => [[$row("臨時\t休館", 'closed')], 'invalid_title'],
            'a line separator in a title' => [[$row("臨時\u{2028}休館", 'closed')], 'invalid_title'],
            'a line break in English' => [[$row('臨時休館', 'closed', "Closed\ntoday")], 'invalid_title'],
            'invalid UTF-8' => [[$row("\xFF休館", 'closed')], 'invalid_title'],
            'a title of 201 characters' => [[$row(str_repeat('あ', 201), 'event')], 'too_long'],
            'English of 201 characters' => [[$row('催し', 'event', str_repeat('x', 201))], 'too_long'],
            '101 rows' => [$tooMany, 'too_many'],
        ];
    }
}
