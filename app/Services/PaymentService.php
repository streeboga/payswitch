<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\PaymentStatusChanged;
use Illuminate\Support\Facades\DB;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Exceptions\PaymentException;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\StateMachine\PaymentStateMachine;

final class PaymentService
{
    public function create(array $data, int|string $merchantAccountId): PaymentIntent
    {
        return PaymentIntent::create([
            'merchant_account_id' => $merchantAccountId,
            'amount' => $data['amount'],
            'currency' => strtoupper($data['currency']),
            'status' => PaymentStatus::RequiresPaymentMethod,
            'capture_method' => $data['capture_method'] ?? CaptureMethod::Automatic,
            'authentication_type' => $data['authentication_type'] ?? 'no_three_ds',
            'customer_id' => $data['customer_id'] ?? null,
            'description' => $data['description'] ?? null,
            'return_url' => $data['return_url'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'session_expiry' => $data['session_expiry'] ?? config('payswitch.payment.session_expiry'),
            'attempt_count' => 1,
            'expires_on' => now()->addSeconds($data['session_expiry'] ?? config('payswitch.payment.session_expiry')),
            'amount_capturable' => $data['amount'],
        ]);
    }

    public function find(string $paymentKey, int|string $merchantAccountId): PaymentIntent
    {
        return PaymentIntent::where('key', $paymentKey)
            ->where('merchant_account_id', $merchantAccountId)
            ->firstOrFail();
    }

    public function confirm(string $paymentKey, array $data, int|string $merchantAccountId): PaymentIntent
    {
        return DB::transaction(function () use ($paymentKey, $data, $merchantAccountId) {
            $payment = PaymentIntent::where('key', $paymentKey)
                ->where('merchant_account_id', $merchantAccountId)
                ->lockForUpdate()
                ->firstOrFail();

            // For MVP: simulate PSP call success
            if ($payment->capture_method === CaptureMethod::Manual) {
                PaymentStateMachine::assertTransition($payment->status, PaymentStatus::RequiresCapture);
                $payment->update([
                    'status' => PaymentStatus::RequiresCapture,
                    'connector' => $data['connector'] ?? 'stripe',
                ]);
            } else {
                PaymentStateMachine::assertTransition($payment->status, PaymentStatus::Succeeded);
                $payment->update([
                    'status' => PaymentStatus::Succeeded,
                    'amount_received' => $payment->amount,
                    'connector' => $data['connector'] ?? 'stripe',
                ]);
            }

            // Create payment attempt
            $payment->paymentAttempts()->create([
                'connector' => $payment->connector,
                'status' => 'succeeded',
                'amount' => $payment->amount,
            ]);

            // Fire event for audit log + webhooks
            event(new PaymentStatusChanged($payment));

            return $payment->fresh();
        });
    }

    public function capture(string $paymentKey, int $amount, int|string $merchantAccountId): PaymentIntent
    {
        return DB::transaction(function () use ($paymentKey, $amount, $merchantAccountId) {
            $payment = PaymentIntent::where('key', $paymentKey)
                ->where('merchant_account_id', $merchantAccountId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($payment->status !== PaymentStatus::RequiresCapture) {
                throw new \Streeboga\PaymentData\Exceptions\InvalidStateTransitionException(
                    $payment->status->value,
                    PaymentStatus::Succeeded->value,
                );
            }

            if ($amount > $payment->amount_capturable) {
                throw new PaymentException(
                    "Capture amount ({$amount}) exceeds capturable amount ({$payment->amount_capturable})",
                    'amount_exceeds_capturable',
                    'payment_error'
                );
            }

            $payment->update([
                'status' => PaymentStatus::Succeeded,
                'amount_received' => $amount,
                'amount_capturable' => 0,
            ]);

            event(new PaymentStatusChanged($payment));

            return $payment->fresh();
        });
    }

    public function cancel(string $paymentKey, int|string $merchantAccountId): PaymentIntent
    {
        return DB::transaction(function () use ($paymentKey, $merchantAccountId) {
            $payment = PaymentIntent::where('key', $paymentKey)
                ->where('merchant_account_id', $merchantAccountId)
                ->lockForUpdate()
                ->firstOrFail();

            PaymentStateMachine::assertTransition($payment->status, PaymentStatus::Cancelled);

            $payment->update(['status' => PaymentStatus::Cancelled]);
            event(new PaymentStatusChanged($payment));

            return $payment->fresh();
        });
    }
}
