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
use Streeboga\PaymentData\Enums\AmountUnit;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\RefundStatus;
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
        } catch (\Exception $e) {
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
        $eventType = $payload['type'] ?? '';

        if (str_contains($eventType, 'refund')) {
            $this->processRefundWebhook($payload, $connectorName);

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

        $newStatus = $connector->mapWebhookEventToStatus($eventType);

        // Fallback for connectors that don't use `type` field (e.g. CloudPayments sends `Status` directly)
        if (! $newStatus && isset($payload['Status'])) {
            // CloudPayments "check" notification: Status=Completed but no AuthCode — it's a
            // validation request ("can I proceed?"), not a payment confirmation. Skip status update.
            // Pay notifications have AuthCode; Fail notifications have Status=Declined.
            if ($payload['Status'] === 'Completed' && ! isset($payload['AuthCode'])) {
                // This is the one moment we get to compare what the payer is about to be
                // charged with what we billed. The widget is handed its amount in the
                // browser, so that number is the payer's to change until we say otherwise.
                return $this->amountMatches($connector, $payload, $payment->amount) ? null : 'amount';
            }

            $newStatus = $connector->mapPaymentStatusToInternal($payload['Status']);
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
     * Does the amount in the notification match what we billed?
     *
     * Providers quote amounts in their own unit — CloudPayments in rubles, most in minor
     * units — and the connector already declares which, so convert rather than guess.
     *
     * @param  array<string, mixed>  $payload
     */
    private function amountMatches(ConnectorInterface $connector, array $payload, int $expectedMinor): bool
    {
        $amount = $payload['Amount'] ?? $payload['amount'] ?? null;

        if (! is_numeric($amount)) {
            return false;
        }

        $notified = $connector::capabilities()->amountUnit === AmountUnit::Rubles
            ? (int) round(((float) $amount) * 100)
            : (int) round((float) $amount);

        if ($notified !== $expectedMinor) {
            Log::warning('Webhook amount does not match the payment', [
                'connector' => $connector->getName(),
                'expected_minor' => $expectedMinor,
                'notified_minor' => $notified,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Process a refund webhook by updating the Refund model status.
     *
     * @param  array<string, mixed>  $payload
     */
    private function processRefundWebhook(array $payload, string $connectorName): void
    {
        $connectorRefundId = $payload['object']['id'] ?? null;
        if (! $connectorRefundId) {
            return;
        }

        $refund = $this->refundRepository->findByConnectorRefundId($connectorRefundId);
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
