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
use Streeboga\PaymentData\Contracts\ConnectorInterface;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Models\Refund;
use Streeboga\PaymentData\StateMachine\PaymentStateMachine;

final readonly class WebhookReceiverService
{
    public function __construct(
        private MerchantRepositoryInterface $merchantRepository,
        private PaymentIntentRepositoryInterface $paymentRepository,
        private RefundRepositoryInterface $refundRepository,
    ) {}

    /**
     * @return array{status: string, code: int}
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

        if (! $connector->verifyWebhookSignature($request->getContent(), $headers)) {
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
            'payload_type' => $payload['type'] ?? 'unknown',
        ]);

        try {
            $this->processWebhook($connector, $mca->merchant_account_id, $payload, $mca->connector_name);
        } catch (\Exception $e) {
            Log::error('Webhook processing failed', [
                'mca_key' => $mcaKey,
                'error' => $e->getMessage(),
            ]);
        }

        return ['status' => 'ok', 'code' => 200];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function processWebhook(
        ConnectorInterface $connector,
        int $merchantAccountId,
        array $payload,
        string $connectorName,
    ): void {
        $eventType = $payload['type'] ?? '';

        if (str_contains($eventType, 'refund')) {
            $this->processRefundWebhook($payload, $connectorName);

            return;
        }

        $paymentId = $connector->extractPaymentIdFromWebhook($payload);
        if (! $paymentId) {
            return;
        }

        $payment = $this->paymentRepository->findByKeyOrNull($paymentId, $merchantAccountId);
        if (! $payment) {
            return;
        }

        $newStatus = $connector->mapWebhookEventToStatus($eventType);
        if (! $newStatus || ! PaymentStateMachine::canTransition($payment->status, $newStatus)) {
            return;
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

        $refund = Refund::where('connector_refund_id', $connectorRefundId)->first();
        if (! $refund) {
            return;
        }

        $eventType = $payload['type'] ?? '';

        if (str_contains($eventType, 'succeeded')) {
            $refund->update([
                'status' => RefundStatus::Succeeded,
                'connector' => $connectorName,
            ]);
        } elseif (str_contains($eventType, 'failed') || str_contains($eventType, 'canceled')) {
            $refund->update([
                'status' => RefundStatus::Failed,
                'connector' => $connectorName,
            ]);
        }
    }
}
