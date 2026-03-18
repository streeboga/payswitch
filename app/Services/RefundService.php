<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeliverWebhookJob;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Exceptions\PaymentException;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\Refund;
use Streeboga\PaymentData\Models\WebhookEvent;

final class RefundService
{
    public function create(array $data, int|string $merchantAccountId): Refund
    {
        $payment = PaymentIntent::where('key', $data['payment_id'])
            ->where('merchant_account_id', $merchantAccountId)
            ->firstOrFail();

        if ($payment->status !== PaymentStatus::Succeeded) {
            throw new PaymentException(
                'Payment must be in succeeded status to create a refund',
                'invalid_payment_status',
                'payment_error'
            );
        }

        if ($data['amount'] > $payment->amount) {
            throw new PaymentException(
                'Refund amount exceeds the original payment amount',
                'amount_exceeds_payment',
                'payment_error'
            );
        }

        $refund = Refund::create([
            'payment_intent_id' => $payment->id,
            'merchant_account_id' => $merchantAccountId,
            'amount' => $data['amount'],
            'currency' => $payment->currency,
            'status' => RefundStatus::Succeeded,
            'reason' => $data['reason'] ?? null,
            'connector' => $payment->connector,
            'metadata' => $data['metadata'] ?? null,
        ]);

        $webhookEvent = WebhookEvent::create([
            'event_type' => 'refund_succeeded',
            'merchant_account_id' => $merchantAccountId,
            'payment_intent_id' => $payment->id,
            'content' => [
                'refund_id' => $refund->key,
                'payment_id' => $payment->key,
                'amount' => $refund->amount,
                'currency' => $refund->currency,
                'status' => $refund->status->value,
            ],
            'delivered' => false,
            'delivery_attempts' => 0,
        ]);

        DeliverWebhookJob::dispatch($webhookEvent);

        return $refund;
    }

    public function find(string $refundKey, int|string $merchantAccountId): Refund
    {
        return Refund::where('key', $refundKey)
            ->where('merchant_account_id', $merchantAccountId)
            ->firstOrFail();
    }
}
