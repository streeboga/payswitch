<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\StateMachine;

use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Exceptions\InvalidStateTransitionException;

final class PaymentStateMachine
{
    /** @var array<string, list<string>> */
    private static array $transitions = [
        'requires_payment_method' => ['requires_confirmation', 'processing', 'requires_capture', 'requires_customer_action', 'succeeded', 'cancelled', 'expired', 'failed'],
        'requires_confirmation' => ['processing', 'requires_capture', 'requires_customer_action', 'cancelled', 'expired', 'failed'],
        'requires_customer_action' => ['processing', 'cancelled', 'expired', 'failed'],
        'requires_merchant_action' => ['processing', 'cancelled', 'failed'],
        'processing' => ['succeeded', 'failed', 'requires_capture', 'requires_customer_action'],
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
