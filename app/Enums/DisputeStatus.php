<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\Enums\HasColor;
use App\Contracts\Enums\HasIcon;
use App\Contracts\Enums\HasLabel;

enum DisputeStatus: string implements HasColor, HasIcon, HasLabel
{
    case Opened = 'opened';
    case EvidenceRequired = 'evidence_required';
    case Resolved = 'resolved';
    case Lost = 'lost';
    case Won = 'won';

    public function getLabel(): string
    {
        return match ($this) {
            self::Opened => 'Открыт',
            self::EvidenceRequired => 'Требуются доказательства',
            self::Resolved => 'Разрешён',
            self::Lost => 'Проигран',
            self::Won => 'Выигран',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Opened => 'info',
            self::EvidenceRequired => 'warning',
            self::Resolved => 'gray',
            self::Lost => 'danger',
            self::Won => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Opened => 'heroicon-o-exclamation-circle',
            self::EvidenceRequired => 'heroicon-o-document-text',
            self::Resolved => 'heroicon-o-check-circle',
            self::Lost => 'heroicon-o-x-circle',
            self::Won => 'heroicon-o-trophy',
        };
    }

    public function canTransitionTo(self $new): bool
    {
        if ($this->isTerminal()) {
            return false;
        }

        return match ($this) {
            self::Opened => in_array($new, [self::EvidenceRequired, self::Resolved, self::Lost, self::Won]),
            self::EvidenceRequired => in_array($new, [self::Resolved, self::Lost, self::Won]),
            default => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Resolved, self::Lost, self::Won]);
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
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
