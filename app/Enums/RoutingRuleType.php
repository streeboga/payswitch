<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\Enums\HasColor;
use App\Contracts\Enums\HasIcon;
use App\Contracts\Enums\HasLabel;

enum RoutingRuleType: string implements HasColor, HasIcon, HasLabel
{
    case Priority = 'priority';
    case RuleBased = 'rule_based';
    case VolumeSplit = 'volume_split';

    public function getLabel(): string
    {
        return match ($this) {
            self::Priority => 'Приоритет',
            self::RuleBased => 'По правилам',
            self::VolumeSplit => 'Разделение трафика',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Priority => 'primary',
            self::RuleBased => 'info',
            self::VolumeSplit => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Priority => 'heroicon-o-arrow-trending-up',
            self::RuleBased => 'heroicon-o-funnel',
            self::VolumeSplit => 'heroicon-o-scale',
        };
    }

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->getLabel();
        }

        return $options;
    }
}
