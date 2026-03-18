<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\Enums\HasLabel;

enum CacheTtl: int implements HasLabel
{
    case OneMinute = 60;
    case FiveMinutes = 300;
    case FifteenMinutes = 900;
    case ThirtyMinutes = 1800;
    case OneHour = 3600;
    case SixHours = 21600;
    case OneDay = 86400;
    case OneWeek = 604800;

    public function getLabel(): string
    {
        return match ($this) {
            self::OneMinute => '1 мин',
            self::FiveMinutes => '5 мин',
            self::FifteenMinutes => '15 мин',
            self::ThirtyMinutes => '30 мин',
            self::OneHour => '1 час',
            self::SixHours => '6 часов',
            self::OneDay => '1 день',
            self::OneWeek => '1 неделя',
        };
    }
}
