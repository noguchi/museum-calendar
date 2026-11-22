<?php

declare(strict_types=1);

namespace MuseumCalendar\Calendar;

interface EventSource
{
    /**
     * YYYY-MM-DD ごとの予定。`start` と `end` は、予定のうちその日に入る部分を H:i で表す。
     * 終日の予定では両方とも ''、終了時刻のない予定では `end` が ''。
     * `description` はプレーンテキストで、説明欄がなければ ''。`url` は http か https のURLで、なければ ''。
     *
     * @return array<string, list<array{title: string, time: string, all_day: bool, start: string, end: string, description: string, url: string}>>
     */
    public function forMonth(Month $month): array;
}
