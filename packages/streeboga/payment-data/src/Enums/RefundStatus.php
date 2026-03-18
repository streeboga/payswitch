<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Enums;

use Streeboga\PaymentData\Contracts\HasColor;
use Streeboga\PaymentData\Contracts\HasLabel;

enum RefundStatus: string implements HasColor, HasLabel
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Pending = 'pending';
    case ManualReview = 'manual_review';

    public function getLabel(): string
    {
        return match ($this) {
            self::Succeeded => 'Успешно',
            self::Failed => 'Неуспешно',
            self::Pending => 'В ожидании',
            self::ManualReview => 'Ручная проверка',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Pending => 'warning',
            self::ManualReview => 'info',
        };
    }
}
