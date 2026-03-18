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

    public function getLabel(): string
    {
        return match ($this) {
            self::Succeeded => 'Успешно',
            self::Failed => 'Ошибка',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Succeeded => 'success',
            self::Failed => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Succeeded => 'heroicon-o-check-circle',
            self::Failed => 'heroicon-o-x-circle',
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
