<?php

declare(strict_types=1);

namespace OtaniCalendar\Calendar;

final class DemoSource implements EventSource
{
    public function forMonth(Month $month): array
    {
        return [
            $month->key() . '-02' => [['title' => 'サンプル開館', 'time' => '10:30–16:30']],
            $month->key() . '-15' => [['title' => 'サンプル展示', 'time' => '10:30–16:30']],
            $month->key() . '-20' => [['title' => 'サンプル休館', 'time' => '終日']],
        ];
    }
}
