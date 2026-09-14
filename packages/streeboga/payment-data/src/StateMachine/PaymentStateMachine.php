<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\StateMachine;

use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Exceptions\InvalidStateTransitionException;

final class PaymentStateMachine
{
    /** @var array<string, list<string>> */
    private static array $transitions = [
        'requires_payment_method' => ['requires_confirmation', 'processing', 'requires_capture', 'requires_customer_action', 'succeeded', 'cancelled', 'expired', 'failed', 'requires_merchant_action'],
        'requires_confirmation' => ['processing', 'requires_capture', 'requires_customer_action', 'cancelled', 'expired', 'failed', 'requires_merchant_action'],
        'requires_customer_action' => ['processing', 'succeeded', 'requires_capture', 'cancelled', 'expired', 'failed', 'requires_merchant_action'],
        'requires_merchant_action' => ['processing', 'cancelled', 'failed'],
        'processing' => ['succeeded', 'failed', 'requires_capture', 'requires_customer_action', 'requires_merchant_action'],
        'requires_capture' => ['succeeded', 'partially_captured', 'partially_captured_and_capturable', 'cancelled'],
        'partially_captured_and_capturable' => ['succeeded', 'partially_captured'],
        'succeeded' => [],
        'failed' => [],
        'cancelled' => [],
        'expired' => [],
        'partially_captured' => [],
    ];

    public static function canTransition(PaymentStatus $from, PaymentStatus $to): bool
    {
        $allowed = self::$transitions[$from->value] ?? [];

        return in_array($to->value, $allowed, true);
    }

    /**
     * Переход по подтверждению провайдера, что деньги у плательщика списаны.
     *
     * Таблица выше считает expired, failed и cancelled конечными, и для наших
     * собственных решений это верно. Но списание у провайдера — факт, а не
     * решение: 3DS или СБП дольше срока платежа, Fail и следом успешный Pay со
     * второй попытки. Отбросить такое подтверждение — значит потерять деньги
     * плательщика молча. Поэтому провайдеру можно больше, и только ему:
     * истёкший или неуспешный платёж становится успешным (или ждёт capture),
     * а отменённый у нас — отдаётся на решение мерчанту.
     */
    public static function canConfirmByProvider(PaymentStatus $from, PaymentStatus $to): bool
    {
        if (self::canTransition($from, $to)) {
            return true;
        }

        return match ($from) {
            PaymentStatus::Expired, PaymentStatus::Failed => in_array(
                $to,
                [PaymentStatus::Succeeded, PaymentStatus::RequiresCapture, PaymentStatus::RequiresMerchantAction],
                true,
            ),
            PaymentStatus::Cancelled => $to === PaymentStatus::RequiresMerchantAction,
            default => false,
        };
    }

    /**
     * @return PaymentStatus[]
     */
    public static function allowedTransitions(PaymentStatus $from): array
    {
        $allowed = self::$transitions[$from->value] ?? [];

        return array_map(
            static fn (string $status): PaymentStatus => PaymentStatus::from($status),
            $allowed,
        );
    }

    /**
     * @throws InvalidStateTransitionException
     */
    public static function assertTransition(PaymentStatus $from, PaymentStatus $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw new InvalidStateTransitionException($from->value, $to->value);
        }
    }
}
