<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\Enums\HasColor;
use App\Contracts\Enums\HasIcon;
use App\Contracts\Enums\HasLabel;

enum PaymentAttemptStatus: string implements HasColor, HasIcon, HasLabel
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case RequiresAction = 'requires_action';

    public function getLabel(): string
    {
        return match ($this) {
            self::Succeeded => 'Успешно',
            self::Failed => 'Ошибка',
            self::RequiresAction => 'Требуется действие',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::RequiresAction => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Succeeded => 'heroicon-o-check-circle',
            self::Failed => 'heroicon-o-x-circle',
            self::RequiresAction => 'heroicon-o-arrow-top-right-on-square',
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
