<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\Enums\HasColor;
use App\Contracts\Enums\HasIcon;
use App\Contracts\Enums\HasLabel;

enum UserRole: string implements HasColor, HasIcon, HasLabel
{
    case Admin = 'admin';
    case Operator = 'operator';
    case Viewer = 'viewer';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Администратор',
            self::Operator => 'Оператор',
            self::Viewer => 'Наблюдатель',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Admin => 'danger',
            self::Operator => 'primary',
            self::Viewer => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Admin => 'heroicon-o-shield-check',
            self::Operator => 'heroicon-o-wrench-screwdriver',
            self::Viewer => 'heroicon-o-eye',
        };
    }

    public function getWeight(): int
    {
        return match ($this) {
            self::Admin => 30,
            self::Operator => 20,
            self::Viewer => 10,
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
