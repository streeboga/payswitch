<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeliverWebhookJob;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use App\Repositories\Contracts\PaymentIntentRepositoryInterface;
use App\Repositories\Contracts\RefundRepositoryInterface;
use App\Repositories\Contracts\WebhookEventRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Streeboga\PaymentConnectors\ConnectorFactory;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Exceptions\PaymentException;
use Streeboga\PaymentData\Models\Refund;

final class RefundService
{
    public function __construct(
        private RefundRepositoryInterface $refundRepository,
        private PaymentIntentRepositoryInterface $paymentRepository,
        private MerchantRepositoryInterface $merchantRepository,
        private WebhookEventRepositoryInterface $webhookRepository,
    ) {}

    public function create(array $data, int|string $merchantAccountId): Refund
    {
        if (! isset($data['payment_id'])) {
            throw new PaymentException('payment_id is required', 'missing_payment_id', 'invalid_request_error', 400);
        }
        if (! isset($data['amount']) || ! is_numeric($data['amount'])) {
            throw new PaymentException('Amount is required and must be numeric', 'missing_amount', 'invalid_request_error', 400);
        }

        return DB::transaction(function () use ($data, $merchantAccountId) {
            $payment = $this->paymentRepository->findByKeyLocked($data['payment_id'], $merchantAccountId);

            if (! in_array($payment->status, [PaymentStatus::Succeeded, PaymentStatus::PartiallyCaptured, PaymentStatus::PartiallyCapturedAndCapturable])) {
                throw new PaymentException(
                    'Payment must be in succeeded or partially captured status to refund',
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

            $totalRefunded = $this->refundRepository->sumPendingAndSucceededForPayment($payment->id);

            if ($data['amount'] > PHP_INT_MAX - $totalRefunded) {
                throw new PaymentException('Amount overflow', 'amount_overflow', 'invalid_request_error', 400);
            }

            if ($payment->amount_received < $data['amount'] + $totalRefunded) {
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

            if (! $lastAttempt || ! $connectorName) {
                throw new PaymentException('No successful payment attempt found for refund', 'missing_attempt', 'invalid_request_error', 400);
            }

            $mca = $this->merchantRepository->findConnectorByMerchantAndName($merchantAccountId, $connectorName);

            if (! $mca) {
                throw new PaymentException('Connector no longer available for refund', 'connector_unavailable', 'invalid_request_error', 502);
            }

            $connector = ConnectorFactory::resolve($mca);
            $refundResult = $connector->refund([
                'amount' => $data['amount'],
                'currency' => $payment->currency,
                'transaction_id' => $lastAttempt->connector_transaction_id,
            ]);

            $refundStatus = $refundResult['success'] ? RefundStatus::Succeeded : RefundStatus::Failed;

            $refund = $this->refundRepository->create([
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
            $webhookEvent = $this->webhookRepository->create([
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
        return $this->refundRepository->findByKey($refundKey, $merchantAccountId);
    }
}
