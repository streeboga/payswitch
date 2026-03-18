<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\Enums\HasColor;
use App\Contracts\Enums\HasIcon;
use App\Contracts\Enums\HasLabel;

enum DisputeType: string implements HasColor, HasIcon, HasLabel
{
    case Chargeback = 'chargeback';
    case Inquiry = 'inquiry';
    case Fraud = 'fraud';

    public function getLabel(): string
    {
        return match ($this) {
            self::Chargeback => 'Чарджбэк',
            self::Inquiry => 'Запрос',
            self::Fraud => 'Фрод',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Chargeback => 'danger',
            self::Inquiry => 'info',
            self::Fraud => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Chargeback => 'heroicon-o-arrow-uturn-left',
            self::Inquiry => 'heroicon-o-question-mark-circle',
            self::Fraud => 'heroicon-o-shield-exclamation',
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
