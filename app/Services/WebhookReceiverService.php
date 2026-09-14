<?php

declare(strict_types=1);

namespace App\Services;

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
            $this->processRefundWebhook($payload, $merchantAccountId, $connectorName);

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

        if (! $newStatus || ! PaymentStateMachine::canTransition($payment->status, $newStatus)) {
            return null;
        }

        $result = DB::transaction(function () use ($payment, $newStatus, $connectorName) {
            $lockedPayment = $this->paymentRepository->findByIdLocked($payment->id);
            if (! $lockedPayment) {
                return null;
            }
            $previousStatus = $lockedPayment->status->value;

            if (PaymentStateMachine::canTransition($lockedPayment->status, $newStatus)) {
                $updateData = [
                    'status' => $newStatus,
                    'connector' => $connectorName,
                ];
                if ($newStatus === PaymentStatus::Succeeded) {
                    $updateData['amount_received'] = $lockedPayment->amount;
                }
                $this->paymentRepository->update($lockedPayment, $updateData);

                $lockedPayment->refresh();

                return ['payment' => $lockedPayment, 'previousStatus' => $previousStatus];
            }

            return null;
        });

        if ($result) {
            event(new PaymentStatusChanged($result['payment'], $result['previousStatus']));
        }

        return null;
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
    private function processRefundWebhook(array $payload, int $merchantAccountId, string $connectorName): void
    {
        $connectorRefundId = $payload['object']['id'] ?? null;
        if (! $connectorRefundId) {
            return;
        }

        $refund = $this->refundRepository->findByConnectorRefundId((string) $connectorRefundId, $merchantAccountId);
        if (! $refund) {
            return;
        }

        if ($refund->status === RefundStatus::Succeeded || $refund->status === RefundStatus::Failed) {
            return;
        }

        $eventType = $payload['type'] ?? '';

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
}
