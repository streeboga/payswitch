<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeliverWebhookJob;
use Illuminate\Support\Facades\DB;
use Streeboga\PaymentConnectors\ConnectorFactory;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Exceptions\PaymentException;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\Refund;
use Streeboga\PaymentData\Models\WebhookEvent;

final class RefundService
{
    public function create(array $data, int|string $merchantAccountId): Refund
    {
        if (!isset($data['payment_id'])) {
            throw new PaymentException('payment_id is required', 'missing_payment_id', 'invalid_request_error', 400);
        }
        if (!isset($data['amount']) || !is_numeric($data['amount'])) {
            throw new PaymentException('Amount is required and must be numeric', 'missing_amount', 'invalid_request_error', 400);
        }

        return DB::transaction(function () use ($data, $merchantAccountId) {
            $payment = PaymentIntent::where('key', $data['payment_id'])
                ->where('merchant_account_id', $merchantAccountId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($payment->status !== PaymentStatus::Succeeded) {
                throw new PaymentException(
                    'Payment must be in succeeded status to refund',
                    'payment_not_succeeded',
                    'invalid_request_error',
                    400,
                );
            }

            if ($data['amount'] <= 0) {
                throw new PaymentException(
                    'Refund amount must be positive',
                    'invalid_amount',
                    'invalid_request_error',
                    400,
                );
            }

            $totalRefunded = Refund::where('payment_intent_id', $payment->id)
                ->whereIn('status', [RefundStatus::Succeeded, RefundStatus::Pending])
                ->sum('amount');

            if ($data['amount'] > PHP_INT_MAX - $totalRefunded) {
                throw new PaymentException('Amount overflow', 'amount_overflow', 'invalid_request_error', 400);
            }

            if ($data['amount'] + $totalRefunded > $payment->amount_received) {
                throw new PaymentException(
                    "Refund amount ({$data['amount']}) plus already refunded ({$totalRefunded}) exceeds payment amount ({$payment->amount_received})",
                    'refund_exceeds_payment',
                    'invalid_request_error',
                    400,
                );
            }

            // Resolve connector from last successful attempt
            $lastAttempt = $payment->paymentAttempts()->where('status', 'succeeded')->latest()->first();
            $connectorName = $payment->connector;

            if (!$lastAttempt || !$connectorName) {
                throw new PaymentException('No successful payment attempt found for refund', 'missing_attempt', 'invalid_request_error', 400);
            }

            $mca = MerchantConnectorAccount::where('merchant_account_id', $merchantAccountId)
                ->where('connector_name', $connectorName)
                ->first();

            if (!$mca) {
                throw new PaymentException('Connector no longer available for refund', 'connector_unavailable', 'invalid_request_error', 502);
            }

            $connector = ConnectorFactory::resolve($mca);
            $refundResult = $connector->refund([
                'amount' => $data['amount'],
                'currency' => $payment->currency,
                'transaction_id' => $lastAttempt->connector_transaction_id,
            ]);

            $refundStatus = $refundResult['success'] ? RefundStatus::Succeeded : RefundStatus::Failed;

            $refund = Refund::create([
                'payment_intent_id' => $payment->id,
                'merchant_account_id' => $merchantAccountId,
                'amount' => $data['amount'],
                'currency' => $payment->currency,
                'status' => $refundStatus,
                'reason' => $data['reason'] ?? null,
                'connector' => $connectorName,
                'connector_refund_id' => $refundResult['transaction_id'] ?? null,
                'error_code' => $refundResult['success'] ? null : ($refundResult['code'] ?? null),
                'error_message' => $refundResult['success'] ? null : ($refundResult['message'] ?? null),
                'metadata' => $data['metadata'] ?? null,
            ]);

            // Webhook event
            $eventType = $refundResult['success'] ? 'refund_succeeded' : 'refund_failed';
            $webhookEvent = WebhookEvent::create([
                'event_type' => $eventType,
                'merchant_account_id' => $merchantAccountId,
                'payment_intent_id' => $payment->id,
                'content' => [
                    'refund_id' => $refund->key,
                    'payment_id' => $payment->key,
                    'amount' => $refund->amount,
                    'currency' => $refund->currency,
                    'status' => $refundStatus->value,
                ],
            ]);
            DeliverWebhookJob::dispatch($webhookEvent->id);

            if (! $refundResult['success']) {
                throw new PaymentException(
                    $refundResult['message'] ?? 'Refund failed at connector',
                    'refund_failed',
                    'connector_error',
                    502,
                );
            }

            return $refund;
        });
    }

    public function find(string $refundKey, int|string $merchantAccountId): Refund
    {
        return Refund::where('key', $refundKey)
            ->where('merchant_account_id', $merchantAccountId)
            ->firstOrFail();
    }
}
