<?php
declare(strict_types=1);

use MuseumCalendar\Calendar\KindTable;
use MuseumCalendar\Frontend\Language;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LanguageTest extends TestCase
{
    private const TOUR = ['title' => 'ガイドツアー開催日', 'time' => '11:00–12:00', 'all_day' => false, 'start' => '11:00', 'end' => '12:00'];
    private const CLOSE = ['title' => '休館日', 'time' => '終日', 'all_day' => true, 'start' => '', 'end' => ''];

    public function testAnOmittedAttributeIsJapaneseAndEnIsEnglish(): void
    {
        self::assertSame(Language::Japanese, Language::fromAttribute(''));
        self::assertSame(Language::English, Language::fromAttribute('en'));
    }

    #[DataProvider('invalidAttributes')]
    public function testRejectsEveryOtherAttribute(string $value): void
    {
        self::assertNull(Language::fromAttribute($value));
    }

    public static function invalidAttributes(): array
    {
        return [['ja'], ['EN'], ['En'], ['en_US'], ['en-US'], ['english'], [' en'], ['en '], ['fr'], ['0']];
    }

    #[DataProvider('texts')]
    public function testTextIsReadInEachLanguage(string $key, string $japanese, string $english): void
    {
        self::assertSame($japanese, Language::Japanese->text($key));
        self::assertSame($english, Language::English->text($key));
    }

    public static function texts(): array
    {
        return [
            ['month_label', '月間カレンダー', 'Monthly calendar'],
            ['today_label', '本日の予定', "Today's schedule"],
            ['scroll_label', '月間予定表（横にスクロールできます）', 'Monthly schedule (scrolls sideways)'],
            ['empty_month', 'この月の予定はありません。', 'No events this month.'],
            ['empty_today', '本日の開館情報はありません。', 'No opening information today.'],
            ['unavailable', '現在、予定を取得できません。時間をおいてご確認ください。', 'The schedule is currently unavailable. Please check again later.'],
            ['not_connected', 'カレンダーの接続が設定されていません。', 'The calendar connection is not set up.'],
            ['invalid_month', '年月の指定が正しくありません。', 'The month is not valid.'],
            ['future_month', 'この月の予定はまだ表示できません。', 'This month cannot be shown yet.'],
            ['invalid_language', '言語の指定が正しくありません。', 'The language is not valid.'],
            ['month_failed', 'この月の予定を表示できません。', 'The schedule for this month cannot be shown.'],
            ['today_failed', '本日の予定を表示できません。', "Today's schedule cannot be shown."],
            ['all_day', '終日', 'All day'],
            ['untitled', '（無題）', '(Untitled)'],
        ];
    }

    public function testTheStaleNoticeEmbedsItsDate(): void
    {
        self::assertSame(
            '更新を確認できていないため、前回取得した予定を表示しています。最終取得：2026/11/16 09:30（日本時間）',
            Language::Japanese->text('stale', '2026/11/16 09:30'),
        );
        self::assertSame(
            'Updates could not be confirmed, so the previously fetched schedule is shown. Last fetched: 2026/11/16 09:30 (Japan time)',
            Language::English->text('stale', '2026/11/16 09:30'),
        );
    }

    #[DataProvider('titles')]
    public function testEnglishTranslatesTheRegisteredTitles(string $title, string $english): void
    {
        self::assertSame($english, Language::English->title($title, KindTable::defaults()));
        self::assertSame($title, Language::Japanese->title($title, KindTable::defaults()));
    }

    public static function titles(): array
    {
        return [
            ['開館日', 'OPEN'],
            ['休館日', 'CLOSED'],
            ['お茶室営業日', 'Tea Room Open'],
            ['ガイドツアー開催日', 'Guided Tour'],
            ['結婚式', 'Wedding'],
            ['夜間特別開館', 'Special Evening Opening'],
            ['コンサート', 'Concert'],
            ['イベント', 'Event'],
            ['（無題）', '(Untitled)'],
            ['　（無題） ', '(Untitled)'],
        ];
    }

    public function testEnglishMatchesTheTrimmedTitle(): void
    {
        self::assertSame('Tea Room Open', Language::English->title("  お茶室営業日\t\n", KindTable::defaults()));
        self::assertSame('Wedding', Language::English->title('　結婚式　', KindTable::defaults()));
        self::assertSame(' お茶室営業日 ', Language::Japanese->title(' お茶室営業日 ', KindTable::defaults()));
    }

    #[DataProvider('unknownTitles')]
    public function testEnglishHasNoTextForAnyOtherTitle(string $title): void
    {
        self::assertNull(Language::English->title($title, KindTable::defaults()));
    }

    public static function unknownTitles(): array
    {
        return [
            '未登録' => ['臨時休館'],
            '行より長い' => ['お茶室営業日（午後）'],
            '行の一部' => ['お茶室'],
            '2つの行' => ['開館日 休館日'],
            'closed を表す英単語' => ['CLOSE'],
            '大文字と小文字の違い' => ['open'],
            '表の英語での表示' => ['Guided Tour'],
            '空' => [''],
            '不正なUTF-8' => ["\xFF結婚式"],
        ];
    }

    public function testJapaneseEventShowsItsTitle(): void
    {
        self::assertSame(self::TOUR + ['label' => 'ガイドツアー開催日', 'label_lang' => ''], Language::Japanese->event(self::TOUR, KindTable::defaults()));
        self::assertSame(self::CLOSE + ['label' => '休館日', 'label_lang' => ''], Language::Japanese->event(self::CLOSE, KindTable::defaults()));
        $unknown = ['title' => '臨時休館'] + self::CLOSE;
        self::assertSame($unknown + ['label' => '臨時休館', 'label_lang' => ''], Language::Japanese->event($unknown, KindTable::defaults()));
    }

    public function testEnglishEventKeepsItsTitleAndShowsTheTranslation(): void
    {
        self::assertSame(self::TOUR + ['label' => 'Guided Tour', 'label_lang' => ''], Language::English->event(self::TOUR, KindTable::defaults()));
        self::assertSame(
            ['title' => '休館日', 'time' => 'All day', 'all_day' => true, 'start' => '', 'end' => '', 'label' => 'CLOSED', 'label_lang' => ''],
            Language::English->event(self::CLOSE, KindTable::defaults()),
        );
    }

    public function testEnglishEventWithoutTranslationShowsItsTitleAsJapanese(): void
    {
        $event = ['title' => ' 臨時休館 '] + self::TOUR;
        self::assertSame($event + ['label' => ' 臨時休館 ', 'label_lang' => 'ja'], Language::English->event($event, KindTable::defaults()));
    }

    public function testEventReplacesDisplayKeysAndKeepsOtherKeys(): void
    {
        $event = self::TOUR + ['label' => 'from a filter', 'label_lang' => 'fr', 'kind' => 'tour', 'category' => 'tour'];
        self::assertSame(
            self::TOUR + ['label' => 'Guided Tour', 'label_lang' => '', 'kind' => 'tour', 'category' => 'tour'],
            Language::English->event($event, KindTable::defaults()),
        );
        self::assertSame(
            self::TOUR + ['label' => 'ガイドツアー開催日', 'label_lang' => '', 'kind' => 'tour', 'category' => 'tour'],
            Language::Japanese->event($event, KindTable::defaults()),
        );
    }

    public function testEnglishFollowsTheGivenTable(): void
    {
        $kinds = KindTable::fromRows([
            ['title' => 'ガイドツアー開催日', 'kind' => 'tour', 'english' => 'Museum Tour', 'color' => ''],
            ['title' => 'ワークショップ', 'kind' => 'workshop', 'english' => 'Workshop', 'color' => ''],
            ['title' => '茶会', 'kind' => 'tea', 'english' => '', 'color' => ''],
        ]);
        self::assertSame('Museum Tour', Language::English->title('ガイドツアー開催日', $kinds));
        self::assertSame('Workshop', Language::English->title(' ワークショップ ', $kinds));
        self::assertNull(Language::English->title('茶会', $kinds));
        self::assertNull(Language::English->title('休館日', $kinds));
        self::assertSame('(Untitled)', Language::English->title('（無題）', $kinds));
        self::assertSame('茶会', Language::Japanese->title('茶会', $kinds));
        self::assertSame(self::TOUR + ['label' => 'Museum Tour', 'label_lang' => ''], Language::English->event(self::TOUR, $kinds));
        $tea = ['title' => '茶会'] + self::TOUR;
        self::assertSame($tea + ['label' => '茶会', 'label_lang' => 'ja'], Language::English->event($tea, $kinds));
        self::assertSame(array_replace(self::CLOSE, ['time' => 'All day']) + ['label' => '休館日', 'label_lang' => 'ja'], Language::English->event(self::CLOSE, $kinds));
    }

    public function testOnlyTheAllDayTimeIsTranslated(): void
    {
        foreach (['10:30–16:30', '10:30', '', '終日まで', '午前'] as $time) {
            self::assertSame($time, Language::English->event(['time' => $time] + self::TOUR, KindTable::defaults())['time']);
        }
        self::assertSame('All day', Language::English->event(['time' => '終日', 'all_day' => false] + self::TOUR, KindTable::defaults())['time']);
    }

    public function testEnglishDateFormatIgnoresTheOption(): void
    {
        foreach (['Y年n月j日', 'n月j日(D)', 'F j, Y', '', false, null] as $option) {
            self::assertSame('D, M j', Language::English->dateFormat($option));
        }
    }

    public function testJapaneseDateFormatIsTheOption(): void
    {
        self::assertSame('n月j日(D)', Language::Japanese->dateFormat('n月j日(D)'));
        self::assertSame('F j, Y', Language::Japanese->dateFormat('F j, Y'));
        self::assertSame('0', Language::Japanese->dateFormat('0'));
    }

    public function testJapaneseDateFormatFallsBackForAnUnusableOption(): void
    {
        foreach (['', false, null, 0, []] as $option) {
            self::assertSame('Y年n月j日', Language::Japanese->dateFormat($option));
        }
    }
}
