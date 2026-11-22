<?php

declare(strict_types=1);

namespace MuseumCalendar\Calendar;

use InvalidArgumentException;

/**
 * 施設がカレンダーに登録するタイトルから、予定の種類と英語での表示を引く表。
 *
 * 表は管理者が設定画面で編集する。DEFAULTS は、表を一度も保存していないサイトの表。
 */
final class KindTable
{
    public const OPEN = 'open';
    public const CLOSED = 'closed';
    /** 表にないタイトルの種類。表の行では使えない。 */
    public const OTHER = 'other';

    public const MAX_ROWS = 100;
    public const MAX_LENGTH = 200;

    /** @var list<array{title: string, kind: string, english: string, color: string}> 色は施設のサイトの予定の背景色。 */
    public const DEFAULTS = [
        ['title' => '開館日', 'kind' => self::OPEN, 'english' => 'OPEN', 'color' => '#ffebee'],
        ['title' => '休館日', 'kind' => self::CLOSED, 'english' => 'CLOSED', 'color' => '#f5f5f5'],
        ['title' => 'お茶室営業日', 'kind' => 'tea', 'english' => 'Tea Room Open', 'color' => '#e8f5e9'],
        ['title' => 'ガイドツアー開催日', 'kind' => 'tour', 'english' => 'Guided Tour', 'color' => '#e3f2fd'],
        ['title' => '結婚式', 'kind' => 'wedding', 'english' => 'Wedding', 'color' => '#fff0fc'],
        ['title' => '夜間特別開館', 'kind' => 'evening', 'english' => 'Special Evening Opening', 'color' => '#fff9f4'],
        ['title' => 'コンサート', 'kind' => 'concert', 'english' => 'Concert', 'color' => '#f5f5f5'],
        ['title' => 'イベント', 'kind' => 'event', 'english' => 'Event', 'color' => '#f5f5f5'],
    ];

    /** @param array<string, array{title: string, kind: string, english: string, color: string}> $byTitle 前後の空白を除いたタイトルをキーにした行 */
    private function __construct(private array $byTitle)
    {
    }

    public static function defaults(): self
    {
        return self::fromRows(self::DEFAULTS);
    }

    /**
     * 設定画面のフォームか保存したオプションから来た行を検査し、正規化する。
     *
     * どの行も4つの項目を文字列で持つ必要がある。そのうえで、前後の空白を除くとタイトルが空になる行は、ほかの項目の値にかかわらず捨てる。
     * `color` が空なら、その行には色がない。
     *
     * @throws InvalidArgumentException メッセージは短いコード。invalid_rows、invalid_title、too_long、duplicate_title、invalid_kind、invalid_color、too_many のいずれか
     */
    public static function fromRows(mixed $rows): self
    {
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new InvalidArgumentException('invalid_rows');
        }
        $byTitle = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['title'] ?? null) || !is_string($row['kind'] ?? null) || !is_string($row['english'] ?? null)
                || !is_string($row['color'] ?? null)) {
                throw new InvalidArgumentException('invalid_rows');
            }
            $title = self::trimmed($row['title']);
            if ($title === '') {
                continue;
            }
            $english = self::trimmed($row['english']);
            $kind = $row['kind'];
            foreach ([$title, $english] as $text) {
                if (preg_match('/^[^\p{Cc}\p{Zl}\p{Zp}]*$/uD', $text) !== 1) {
                    throw new InvalidArgumentException('invalid_title');
                }
                if (mb_strlen($text, 'UTF-8') > self::MAX_LENGTH) {
                    throw new InvalidArgumentException('too_long');
                }
            }
            if (isset($byTitle[$title])) {
                throw new InvalidArgumentException('duplicate_title');
            }
            if (preg_match('/^[a-z][a-z0-9-]{0,39}$/D', $kind) !== 1 || $kind === self::OTHER) {
                throw new InvalidArgumentException('invalid_kind');
            }
            $color = strtolower(self::trimmed($row['color']));
            if ($color !== '' && preg_match('/^#[0-9a-f]{6}$/D', $color) !== 1) {
                throw new InvalidArgumentException('invalid_color');
            }
            $byTitle[$title] = ['title' => $title, 'kind' => $kind, 'english' => $english, 'color' => $color];
        }
        if (count($byTitle) > self::MAX_ROWS) {
            throw new InvalidArgumentException('too_many');
        }
        return new self($byTitle);
    }

    /** @return list<array{title: string, kind: string, english: string, color: string}> */
    public function rows(): array
    {
        return array_values($this->byTitle);
    }

    /** 前後の空白を除いたタイトル全体が、大文字と小文字も含めて行と一致したときだけ当てはまる。 */
    public function kind(string $title): string
    {
        return $this->byTitle[self::trimmed($title)]['kind'] ?? self::OTHER;
    }

    /** 登録したタイトルに代えて表示する英語。表にないタイトルと、英語での表示が空欄の行では null。 */
    public function english(string $title): ?string
    {
        $english = $this->byTitle[self::trimmed($title)]['english'] ?? '';
        return $english === '' ? null : $english;
    }

    /** 行の背景色。小文字の #rrggbb。表にないタイトルと、色のない行では ''。 */
    public function color(string $title): string
    {
        return $this->byTitle[self::trimmed($title)]['color'] ?? '';
    }

    /** 前後の空白を除いたタイトル。全角スペースも空白として扱う。 */
    public static function trimmed(string $title): string
    {
        return preg_replace('/^\s+|\s+$/u', '', $title) ?? $title;
    }
}
