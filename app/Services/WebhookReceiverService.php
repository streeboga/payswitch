<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PaymentAttemptStatus;
use App\Events\PaymentStatusChanged;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use App\Repositories\Contracts\PaymentIntentRepositoryInterface;
use App\Repositories\Contracts\RefundRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Streeboga\PaymentConnectors\ConnectorFactory;
use Streeboga\PaymentConnectors\Drivers\YooKassaConnector;
use Streeboga\PaymentData\Contracts\ConnectorInterface;
use Streeboga\PaymentData\Contracts\WebhookAcknowledging;
use Streeboga\PaymentData\Contracts\WebhookEventReading;
use Streeboga\PaymentData\Enums\AmountUnit;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\StateMachine\PaymentStateMachine;

final readonly class WebhookReceiverService
{
    public function __construct(
        private MerchantRepositoryInterface $merchantRepository,
        private PaymentIntentRepositoryInterface $paymentRepository,
        private RefundRepositoryInterface $refundRepository,
        private WebhookService $webhookService,
    ) {}

    /**
     * @return array{status: string, code: int, ack?: array<string, mixed>|null}
     */
    public function handle(Request $request, string $merchantKey, string $mcaKey): array
    {
        $merchant = $this->merchantRepository->findMerchantByKeyOrNull($merchantKey);
        if (! $merchant) {
            return ['status' => 'ignored', 'code' => 404];
        }

        $mca = $this->merchantRepository->findConnectorByMerchantAndKeyOrNull($merchant->id, $mcaKey);
        if (! $mca) {
            return ['status' => 'ignored', 'code' => 404];
        }

        $connector = ConnectorFactory::resolve($mca);

        $headers = collect($request->headers->all())
            ->map(fn (array $values) => $values[0] ?? null)
            ->toArray();

        // Written last so a client sending this header cannot forge its own source address.
        $headers[YooKassaConnector::SOURCE_IP_HEADER] = $request->ip();

        // What the provider signed: the body for a POST, the query string for a GET
        // callback (RBS sends one of those, and it has no body at all).
        $raw = $request->isMethod('GET')
            ? ($request->getQueryString() ?? '')
            : $request->getContent();

        if (! $connector->verifyWebhookSignature($raw, $headers)) {
            Log::warning('Webhook signature verification failed', [
                'merchant_key' => $merchantKey,
                'mca_key' => $mcaKey,
                'connector' => $mca->connector_name,
            ]);

            return ['status' => 'invalid_signature', 'code' => 401];
        }

        $payload = $request->all();

        Log::info("Incoming webhook from {$mca->connector_name}", [
            'mca_key' => $mcaKey,
            'payload_type' => $payload['type'] ?? ($payload['Status'] ?? 'unknown'),
        ]);

        try {
            $refusal = $this->processWebhook($connector, $mca->merchant_account_id, $payload, $mca->connector_name);
        } catch (\Throwable $e) {
            // Throwable, not Exception: a payload of the wrong shape surfaces as a TypeError
            // and used to escape as a 500.
            Log::error('Webhook processing failed', [
                'mca_key' => $mcaKey,
                'error' => $e->getMessage(),
            ]);

            // We do not know what we just failed to record, so we do not vouch for it.
            $refusal = 'unacceptable';
        }

        return [
            'status' => 'ok',
            'code' => 200,
            'ack' => $connector instanceof WebhookAcknowledging ? $connector->webhookAck($refusal) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return string|null Why we refuse to vouch for this notification, or null if we do.
     *                     See WebhookAcknowledging.
     */
    private function processWebhook(
        ConnectorInterface $connector,
        int $merchantAccountId,
        array $payload,
        string $connectorName,
    ): ?string {
        $eventType = $connector instanceof WebhookEventReading
            ? $connector->webhookEventType($payload)
            : (string) ($payload['type'] ?? '');

        if (str_contains($eventType, 'refund')) {
            $this->processRefundWebhook($connector, $payload, $eventType, $merchantAccountId, $connectorName);

            return null;
        }

        $paymentId = $connector->extractPaymentIdFromWebhook($payload);
        if (! $paymentId) {
            return 'unacceptable';
        }

        $payment = $this->paymentRepository->findByKeyOrNull($paymentId, $merchantAccountId);
        if (! $payment) {
            return 'unacceptable';
        }

        // The URL names one connector of the merchant, and some of them sign nothing (the
        // test ones). A payment another connector conducts is not this one's to move.
        if ($payment->connector !== null && $payment->connector !== $connectorName) {
            Log::warning('Webhook from a connector that does not conduct the payment', [
                'payment_id' => $payment->key,
                'payment_connector' => $payment->connector,
                'webhook_connector' => $connectorName,
            ]);

            return 'unacceptable';
        }

        if ($eventType === WebhookEventReading::CHECK) {
            return $this->checkRefusal($connector, $payload, $payment);
        }

        $newStatus = $connector->mapWebhookEventToStatus($eventType);

        // Fallback for connectors that don't use `type` field (e.g. T-Bank sends `Status` directly)
        if (! $newStatus && isset($payload['Status'])) {
            $newStatus = $connector->mapPaymentStatusToInternal((string) $payload['Status']);
        }

        if (! $newStatus) {
            return null;
        }

        $result = DB::transaction(function () use ($connector, $payload, $payment, $newStatus, $connectorName) {
            $lockedPayment = $this->paymentRepository->findByIdLocked($payment->id);
            if (! $lockedPayment) {
                return null;
            }
            $from = $lockedPayment->status;
            $updateData = ['status' => $newStatus, 'connector' => $connectorName];

            // The provider reports money taken from the payer. That is a fact, not a request,
            // and our own verdict on the payment does not undo it.
            $charged = $newStatus === PaymentStatus::Succeeded || $newStatus === PaymentStatus::RequiresCapture;

            // What the provider says it took. A notification that quotes no amount (Stripe,
            // YooKassa nest it elsewhere) is credited with what we billed, as before.
            $notifiedAmount = $this->notifiedAmount($connector, $payload);
            $mismatch = $charged && $notifiedAmount !== null
                ? $this->mismatch($connector, $payload, $lockedPayment, $notifiedAmount)
                : null;

            if ($charged && $mismatch !== null
                && PaymentStateMachine::canConfirmByProvider($from, PaymentStatus::RequiresMerchantAction)) {
                // Money moved, but not the money we billed: the payer may have edited the
                // amount or currency in the browser. Not ours to call it paid.
                $updateData['status'] = PaymentStatus::RequiresMerchantAction;
                $updateData['error_code'] = $mismatch;
                $updateData['error_message'] = 'The provider charged a different amount or currency than billed';
            } elseif ($charged && $from === PaymentStatus::Cancelled) {
                // Cancelled here, paid there: refund or deliver is the merchant's call.
                $updateData['status'] = PaymentStatus::RequiresMerchantAction;
                $updateData['error_code'] = 'paid_after_cancellation';
                $updateData['error_message'] = 'The provider charged the payer after the payment was cancelled';
                Log::error('Webhook confirms a charge on a cancelled payment', [
                    'payment_id' => $lockedPayment->key,
                    'connector' => $connectorName,
                ]);
            } elseif ($charged && ! PaymentStateMachine::canTransition($from, $newStatus)
                && PaymentStateMachine::canConfirmByProvider($from, $newStatus)) {
                // 3DS or SBP outlasting the payment's lifetime, or a second try after a Fail.
                $updateData['error_code'] = null;
                $updateData['error_message'] = null;
                Log::warning('Webhook confirms a late payment', [
                    'payment_id' => $lockedPayment->key,
                    'from' => $from->value,
                    'to' => $newStatus->value,
                    'connector' => $connectorName,
                ]);
            } elseif (! PaymentStateMachine::canTransition($from, $newStatus)) {
                return null;
            }

            if ($updateData['status'] === PaymentStatus::Succeeded) {
                $updateData['amount_received'] = $notifiedAmount ?? $lockedPayment->amount;
            }
            $this->paymentRepository->update($lockedPayment, $updateData);
            $this->recordAttemptOutcome($lockedPayment, $connectorName, $updateData['status'], $payload);

            $lockedPayment->refresh();

            return ['payment' => $lockedPayment, 'previousStatus' => $from->value];
        });

        if ($result) {
            event(new PaymentStatusChanged($result['payment'], $result['previousStatus']));
        }

        return null;
    }

    /**
     * Carry the outcome over to the payment's attempt, with the provider's transaction id.
     *
     * Refund and capture look for a succeeded attempt, sync for one with a transaction id.
     * Without this a payment confirmed by notification stayed with a `requires_action`
     * attempt and no id, and nothing could be done with it but from the provider's cabinet.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordAttemptOutcome(PaymentIntent $payment, string $connectorName, PaymentStatus $status, array $payload): void
    {
        $failed = $status === PaymentStatus::Failed;
        if (! $failed && ! in_array($status, [PaymentStatus::Succeeded, PaymentStatus::RequiresCapture, PaymentStatus::RequiresMerchantAction], true)) {
            return;
        }

        $transactionId = $payload['TransactionId'] ?? null;
        $attributes = array_filter([
            'status' => $failed ? PaymentAttemptStatus::Failed->value : PaymentAttemptStatus::Succeeded->value,
            'connector_transaction_id' => is_scalar($transactionId) ? (string) $transactionId : null,
        ], fn ($value) => $value !== null);

        // A late success may follow a Fail that already closed the attempt.
        $open = $failed ? ['requires_action', 'processing'] : ['requires_action', 'processing', 'failed'];

        $attempt = $payment->paymentAttempts()
            ->where('connector', $connectorName)
            ->whereIn('status', $open)
            ->latest('id')
            ->first();

        if ($attempt) {
            $attempt->update($attributes);
        } elseif (! $failed) {
            $this->paymentRepository->createAttempt($payment, $attributes + [
                'connector' => $connectorName,
                'amount' => $payment->amount,
            ]);
        }
    }

    /**
     * Answer to a pre-charge check: may the provider take the payer's money?
     *
     * This is the one moment we get to compare what the payer is about to be charged with
     * what we billed. The widget is handed its amount and currency in the browser, so
     * those are the payer's to change until we say otherwise. And a payment that is
     * already paid, cancelled or out of time must not be paid again.
     *
     * @param  array<string, mixed>  $payload
     */
    private function checkRefusal(ConnectorInterface $connector, array $payload, PaymentIntent $payment): ?string
    {
        if ($payment->status === PaymentStatus::Expired) {
            return 'expired';
        }

        $payable = [
            PaymentStatus::RequiresPaymentMethod,
            PaymentStatus::RequiresConfirmation,
            PaymentStatus::RequiresCustomerAction,
            PaymentStatus::Processing,
        ];
        if (! in_array($payment->status, $payable, true)) {
            Log::warning('Check refused: payment can no longer be paid', [
                'payment_id' => $payment->key,
                'status' => $payment->status->value,
            ]);

            return 'unacceptable';
        }

        $notifiedAmount = $this->notifiedAmount($connector, $payload);

        return $notifiedAmount === null || $this->mismatch($connector, $payload, $payment, $notifiedAmount) !== null
            ? 'amount'
            : null;
    }

    /**
     * The amount the provider quotes in the notification, in minor units, or null if it
     * quotes none.
     *
     * Providers quote amounts in their own unit — CloudPayments in rubles, most in minor
     * units — and the connector already declares which, so convert rather than guess.
     *
     * @param  array<string, mixed>  $payload
     */
    private function notifiedAmount(ConnectorInterface $connector, array $payload): ?int
    {
        $amount = $payload['Amount'] ?? $payload['amount'] ?? null;

        if (! is_numeric($amount)) {
            return null;
        }

        return $connector::capabilities()->amountUnit === AmountUnit::Rubles
            ? (int) round(((float) $amount) * 100)
            : (int) round((float) $amount);
    }

    /**
     * 'amount_mismatch' or 'currency_mismatch' when the notification is not for what we
     * billed, null when it is. A currency the notification does not quote is not held
     * against it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function mismatch(ConnectorInterface $connector, array $payload, PaymentIntent $payment, int $notifiedAmount): ?string
    {
        $currency = $payload['Currency'] ?? $payload['currency'] ?? null;

        $mismatch = match (true) {
            $notifiedAmount !== $payment->amount => 'amount_mismatch',
            is_string($currency) && strtoupper($currency) !== strtoupper($payment->currency) => 'currency_mismatch',
            default => null,
        };

        if ($mismatch !== null) {
            Log::error('Webhook amount or currency does not match the payment', [
                'connector' => $connector->getName(),
                'payment_id' => $payment->key,
                'mismatch' => $mismatch,
                'expected' => [$payment->amount, $payment->currency],
                'notified' => [$notifiedAmount, $currency],
            ]);
        }

        return $mismatch;
    }

    /**
     * Process a refund webhook by updating the Refund model status.
     *
     * @param  array<string, mixed>  $payload
     */
    private function processRefundWebhook(
        ConnectorInterface $connector,
        array $payload,
        string $eventType,
        int $merchantAccountId,
        string $connectorName,
    ): void {
        // YooKassa nests the refund, CloudPayments sends its transaction flat.
        $connectorRefundId = $payload['object']['id'] ?? $payload['TransactionId'] ?? null;
        if (! is_scalar($connectorRefundId) || (string) $connectorRefundId === '') {
            return;
        }
        $connectorRefundId = (string) $connectorRefundId;

        $refund = $this->refundRepository->findByConnectorRefundId($connectorRefundId, $merchantAccountId);
        if (! $refund) {
            if (str_contains($eventType, 'succeeded')) {
                $this->recordProviderRefund($connector, $payload, $connectorRefundId, $merchantAccountId, $connectorName);
            }

            return;
        }

        if ($refund->status === RefundStatus::Succeeded || $refund->status === RefundStatus::Failed) {
            return;
        }

        if (str_contains($eventType, 'succeeded')) {
            $this->refundRepository->updateRefund($refund, [
                'status' => RefundStatus::Succeeded,
                'connector' => $connectorName,
            ]);
        } elseif (str_contains($eventType, 'failed') || str_contains($eventType, 'canceled')) {
            $this->refundRepository->updateRefund($refund, [
                'status' => RefundStatus::Failed,
                'connector' => $connectorName,
            ]);
        }
    }

    /**
     * A refund we did not make: done in the provider's cabinet. The payer already has the
     * money back, so the merchant — and whoever credited the payment — must hear of it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordProviderRefund(
        ConnectorInterface $connector,
        array $payload,
        string $connectorRefundId,
        int $merchantAccountId,
        string $connectorName,
    ): void {
        $paymentId = $connector->extractPaymentIdFromWebhook($payload);
        $amount = $this->notifiedAmount($connector, $payload);
        $payment = $paymentId ? $this->paymentRepository->findByKeyOrNull($paymentId, $merchantAccountId) : null;

        if (! $payment || $amount === null || ($payment->connector !== null && $payment->connector !== $connectorName)) {
            Log::warning('Refund notification for a payment we cannot match', [
                'connector' => $connectorName,
                'connector_refund_id' => $connectorRefundId,
                'payment_id' => $paymentId,
            ]);

            return;
        }

        $refund = DB::transaction(function () use ($payment, $amount, $connectorRefundId, $merchantAccountId, $connectorName) {
            // The provider repeats notifications; the lock keeps two copies from both passing
            // the lookup above.
            $this->paymentRepository->findByIdLocked($payment->id);
            if ($this->refundRepository->findByConnectorRefundId($connectorRefundId, $merchantAccountId)) {
                return null;
            }

            return $this->refundRepository->create([
                'payment_intent_id' => $payment->id,
                'merchant_account_id' => $merchantAccountId,
                'amount' => $amount,
                'currency' => $payment->currency,
                'status' => RefundStatus::Succeeded,
                'reason' => 'Refunded at the provider',
                'connector' => $connectorName,
                'connector_refund_id' => $connectorRefundId,
            ]);
        });

        if ($refund) {
            $this->webhookService->dispatchForRefund($refund, $payment);
        }
    }
}
