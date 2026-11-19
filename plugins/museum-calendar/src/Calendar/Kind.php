<?php

declare(strict_types=1);

namespace MuseumCalendar\Calendar;

/** The kind of an event, read from the title the facility registers in its calendar. */
enum Kind: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Tea = 'tea';
    case Tour = 'tour';
    case Wedding = 'wedding';
    case Evening = 'evening';
    case Concert = 'concert';
    case Event = 'event';
    case Other = 'other';

    /** Only the whole title matches, without the spaces around it, in the same case as a row. */
    public static function fromTitle(string $title): self
    {
        return match (self::trimmed($title)) {
            'OPEN' => self::Open,
            'CLOSE' => self::Closed,
            'お茶室営業日' => self::Tea,
            'ガイドツアー開催日' => self::Tour,
            '結婚式' => self::Wedding,
            '夜間特別開館' => self::Evening,
            'コンサート' => self::Concert,
            'イベント' => self::Event,
            default => self::Other,
        };
    }

    /** The English text that replaces the registered title. Null for a title outside the table. */
    public function english(): ?string
    {
        return match ($this) {
            self::Open => 'OPEN',
            self::Closed => 'CLOSED',
            self::Tea => 'Tea Room Open',
            self::Tour => 'Guided Tour',
            self::Wedding => 'Wedding',
            self::Evening => 'Special Evening Opening',
            self::Concert => 'Concert',
            self::Event => 'Event',
            self::Other => null,
        };
    }

    /** A title without the spaces around it. A full-width space counts as a space. */
    public static function trimmed(string $title): string
    {
        return preg_replace('/^\s+|\s+$/u', '', $title) ?? $title;
    }
}
