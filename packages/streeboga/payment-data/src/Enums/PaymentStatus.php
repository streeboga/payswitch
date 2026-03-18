<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Enums;

use App\Contracts\Enums\HasColor;
use App\Contracts\Enums\HasLabel;
use Streeboga\PaymentData\StateMachine\PaymentStateMachine;

enum PaymentStatus: string implements HasLabel, HasColor
{
    case RequiresPaymentMethod = 'requires_payment_method';
    case RequiresConfirmation = 'requires_confirmation';
    case RequiresCustomerAction = 'requires_customer_action';
    case RequiresMerchantAction = 'requires_merchant_action';
    case Processing = 'processing';
    case RequiresCapture = 'requires_capture';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case PartiallyCaptured = 'partially_captured';
    case PartiallyCapturedAndCapturable = 'partially_captured_and_capturable';

    public function getLabel(): string
    {
        return match ($this) {
            self::RequiresPaymentMethod => 'Требуется метод оплаты',
            self::RequiresConfirmation => 'Требуется подтверждение',
            self::RequiresCustomerAction => 'Требуется действие клиента',
            self::RequiresMerchantAction => 'Требуется действие мерчанта',
            self::Processing => 'В обработке',
            self::RequiresCapture => 'Требуется списание',
            self::Succeeded => 'Успешно',
            self::Cancelled => 'Отменён',
            self::Failed => 'Неуспешно',
            self::Expired => 'Истёк',
            self::PartiallyCaptured => 'Частично списан',
            self::PartiallyCapturedAndCapturable => 'Частично списан, доступен для списания',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Processing, self::RequiresCapture => 'warning',
            self::RequiresPaymentMethod, self::RequiresConfirmation, self::RequiresCustomerAction, self::RequiresMerchantAction => 'info',
            self::Cancelled, self::Expired => 'gray',
            self::PartiallyCaptured, self::PartiallyCapturedAndCapturable => 'warning',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Succeeded,
            self::Cancelled,
            self::Failed,
            self::Expired,
            self::PartiallyCaptured,
        ], true);
    }

    public function canTransitionTo(self $new): bool
    {
        return PaymentStateMachine::canTransition($this, $new);
    }
}
