<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\Enums\HasColor;
use App\Contracts\Enums\HasIcon;
use App\Contracts\Enums\HasLabel;

enum WebhookEventType: string implements HasColor, HasIcon, HasLabel
{
    case PaymentSucceeded = 'payment_succeeded';
    case PaymentCaptured = 'payment_captured';
    case PaymentCancelled = 'payment_cancelled';
    case PaymentAuthorized = 'payment_authorized';
    case PaymentStatusChanged = 'payment_status_changed';
    case RefundSucceeded = 'refund_succeeded';
    case RefundFailed = 'refund_failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::PaymentSucceeded => 'Платёж успешен',
            self::PaymentCaptured => 'Платёж списан',
            self::PaymentCancelled => 'Платёж отменён',
            self::PaymentAuthorized => 'Платёж авторизован',
            self::PaymentStatusChanged => 'Статус платежа изменён',
            self::RefundSucceeded => 'Возврат успешен',
            self::RefundFailed => 'Возврат не удался',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PaymentSucceeded => 'success',
            self::PaymentCaptured => 'success',
            self::PaymentCancelled => 'danger',
            self::PaymentAuthorized => 'info',
            self::PaymentStatusChanged => 'warning',
            self::RefundSucceeded => 'success',
            self::RefundFailed => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::PaymentSucceeded => 'heroicon-o-check-circle',
            self::PaymentCaptured => 'heroicon-o-banknotes',
            self::PaymentCancelled => 'heroicon-o-x-circle',
            self::PaymentAuthorized => 'heroicon-o-lock-open',
            self::PaymentStatusChanged => 'heroicon-o-arrow-path',
            self::RefundSucceeded => 'heroicon-o-receipt-refund',
            self::RefundFailed => 'heroicon-o-exclamation-triangle',
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
