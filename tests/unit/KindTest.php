<?php
declare(strict_types=1);

use MuseumCalendar\Calendar\Kind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KindTest extends TestCase
{
    #[DataProvider('rows')]
    public function testEachRegisteredTitleHasItsKindAndEnglishText(string $title, string $kind, string $english): void
    {
        self::assertSame($kind, Kind::fromTitle($title)->value);
        self::assertSame($english, Kind::fromTitle($title)->english());
    }

    public static function rows(): array
    {
        return [
            ['OPEN', 'open', 'OPEN'],
            ['CLOSE', 'closed', 'CLOSED'],
            ['お茶室営業日', 'tea', 'Tea Room Open'],
            ['ガイドツアー開催日', 'tour', 'Guided Tour'],
            ['結婚式', 'wedding', 'Wedding'],
            ['夜間特別開館', 'evening', 'Special Evening Opening'],
            ['コンサート', 'concert', 'Concert'],
            ['イベント', 'event', 'Event'],
        ];
    }

    public function testTheSpacesAroundATitleAreIgnored(): void
    {
        self::assertSame(Kind::Tea, Kind::fromTitle("  お茶室営業日\t\n"));
        self::assertSame(Kind::Wedding, Kind::fromTitle('　結婚式　'));
        self::assertSame(Kind::Concert, Kind::fromTitle(' 　コンサート　 '));
    }

    #[DataProvider('otherTitles')]
    public function testAnyOtherTitleIsOtherWithoutEnglishText(string $title): void
    {
        self::assertSame(Kind::Other, Kind::fromTitle($title));
        self::assertNull(Kind::fromTitle($title)->english());
    }

    public static function otherTitles(): array
    {
        return [
            'not registered' => ['臨時休館'],
            'longer than a row' => ['お茶室営業日（午後）'],
            'part of a row' => ['お茶室'],
            'a row inside a longer title' => ['アフタヌーンコンサート'],
            'a space inside' => ['ガイド ツアー開催日'],
            'two rows' => ['OPEN CLOSE'],
            'lower case' => ['open'],
            'mixed case' => ['Close'],
            'an English text of the table' => ['Guided Tour'],
            'a kind' => ['tour'],
            'untitled' => ['（無題）'],
            'empty' => [''],
            'invalid UTF-8' => ["\xFF結婚式"],
        ];
    }

    public function testTrimmedDropsOnlyTheSpacesAroundATitle(): void
    {
        self::assertSame('臨時休館', Kind::trimmed(" \t臨時休館\n"));
        self::assertSame('臨時休館', Kind::trimmed('　臨時休館　'));
        self::assertSame('臨時 休館　午後', Kind::trimmed(' 臨時 休館　午後 '));
        self::assertSame('', Kind::trimmed('   '));
    }
}
