<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\Enums\HasColor;
use App\Contracts\Enums\HasIcon;
use App\Contracts\Enums\HasLabel;

enum ApiKeyType: string implements HasColor, HasIcon, HasLabel
{
    case Admin = 'admin';
    case Secret = 'secret';
    case Publishable = 'publishable';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Secret => 'Secret',
            self::Publishable => 'Publishable',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Admin => 'danger',
            self::Secret => 'warning',
            self::Publishable => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Admin => 'heroicon-o-shield-exclamation',
            self::Secret => 'heroicon-o-key',
            self::Publishable => 'heroicon-o-globe-alt',
        };
    }

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
