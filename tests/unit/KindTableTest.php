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
            '未登録' => ['臨時休館'],
            '行より長い' => ['お茶室営業日（午後）'],
            '行の一部' => ['お茶室'],
            '長いタイトルの中の行' => ['アフタヌーンコンサート'],
            '内側の空白' => ['ガイド ツアー開催日'],
            '2つの行' => ['開館日 休館日'],
            'open の行の英語での表示' => ['OPEN'],
            'closed を表す英単語' => ['CLOSE'],
            '行の種類' => ['open'],
            '内側の全角の空白' => ['休館　日'],
            '表の英語での表示' => ['Guided Tour'],
            '種類' => ['tour'],
            '無題' => ['（無題）'],
            '空' => [''],
            '不正なUTF-8' => ["\xFF結婚式"],
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
            '配列でない' => ['OPEN', 'invalid_rows'],
            'null' => [null, 'invalid_rows'],
            'タイトルをキーにした表' => [['OPEN' => $row('OPEN', 'open')], 'invalid_rows'],
            '文字列の行' => [['OPEN'], 'invalid_rows'],
            'kind のない行' => [[['title' => 'OPEN', 'english' => 'OPEN']], 'invalid_rows'],
            'english のない行' => [[['title' => 'OPEN', 'kind' => 'open']], 'invalid_rows'],
            'color のない行' => [[['title' => 'OPEN', 'kind' => 'open', 'english' => '']], 'invalid_rows'],
            'color と title のない行' => [[['title' => '', 'kind' => '', 'english' => '']], 'invalid_rows'],
            '文字列でないタイトル' => [[['title' => 1, 'kind' => 'open', 'english' => '']], 'invalid_rows'],
            'null の種類' => [[['title' => 'OPEN', 'kind' => null, 'english' => '']], 'invalid_rows'],
            'null の色' => [[['title' => 'OPEN', 'kind' => 'open', 'english' => '', 'color' => null]], 'invalid_rows'],
            '配列の色' => [[['title' => 'OPEN', 'kind' => 'open', 'english' => '', 'color' => ['#ffffff']]], 'invalid_rows'],
            '色の名前' => [[$row('OPEN', 'open', '', 'red')], 'invalid_color'],
            '短い16進数の色' => [[$row('OPEN', 'open', '', '#fff')], 'invalid_color'],
            'アルファ付きの16進数の色' => [[$row('OPEN', 'open', '', '#ffffff80')], 'invalid_color'],
            '# のない16進数の色' => [[$row('OPEN', 'open', '', 'ffffff')], 'invalid_color'],
            '16進数でない桁' => [[$row('OPEN', 'open', '', '#12345g')], 'invalid_color'],
            '関数' => [[$row('OPEN', 'open', '', 'rgb(0, 0, 0)')], 'invalid_color'],
            '後に宣言が続く色' => [[$row('OPEN', 'open', '', '#ffffff;color:red')], 'invalid_color'],
            '色の中の改行' => [[$row('OPEN', 'open', '', "#ff\nffff")], 'invalid_color'],
            '重複したタイトル' => [[$row('OPEN', 'open'), $row('CLOSE', 'closed'), $row(' OPEN　', 'event')], 'duplicate_title'],
            '空の種類' => [[$row('OPEN', '')], 'invalid_kind'],
            'other' => [[$row('臨時休館', 'other')], 'invalid_kind'],
            '大文字' => [[$row('OPEN', 'Open')], 'invalid_kind'],
            '先頭の数字' => [[$row('OPEN', '1st')], 'invalid_kind'],
            '先頭のハイフン' => [[$row('OPEN', '-open')], 'invalid_kind'],
            '空白' => [[$row('OPEN', 'open ')], 'invalid_kind'],
            'アンダースコア' => [[$row('OPEN', 'open_day')], 'invalid_kind'],
            '引用符' => [[$row('OPEN', 'open"')], 'invalid_kind'],
            '末尾の改行' => [[$row('OPEN', "open\n")], 'invalid_kind'],
            '41文字の種類' => [[$row('OPEN', 'x' . str_repeat('9', 40))], 'invalid_kind'],
            'タイトルの改行' => [[$row("臨時\n休館", 'closed')], 'invalid_title'],
            'タイトルのタブ' => [[$row("臨時\t休館", 'closed')], 'invalid_title'],
            'タイトルの行区切り文字' => [[$row("臨時\u{2028}休館", 'closed')], 'invalid_title'],
            '英語での表示の改行' => [[$row('臨時休館', 'closed', "Closed\ntoday")], 'invalid_title'],
            '不正なUTF-8' => [[$row("\xFF休館", 'closed')], 'invalid_title'],
            '201文字のタイトル' => [[$row(str_repeat('あ', 201), 'event')], 'too_long'],
            '201文字の英語での表示' => [[$row('催し', 'event', str_repeat('x', 201))], 'too_long'],
            '101行' => [$tooMany, 'too_many'],
        ];
    }
}
