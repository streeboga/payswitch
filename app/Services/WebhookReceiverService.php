<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ConnectorName;
use App\Events\PaymentStatusChanged;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use App\Repositories\Contracts\PaymentIntentRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\StateMachine\PaymentStateMachine;

final class WebhookReceiverService
{
    public function __construct(
        private MerchantRepositoryInterface $merchantRepository,
        private PaymentIntentRepositoryInterface $paymentRepository,
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

        if (! $this->verifySignature($request, $mca)) {
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
            $this->processWebhook($mca, $payload);
        } catch (\Exception $e) {
            Log::error('Webhook processing failed', [
                'mca_key' => $mcaKey,
                'error' => $e->getMessage(),
            ]);
        }

        return ['status' => 'ok', 'code' => 200];
    }

    private function verifySignature(Request $request, MerchantConnectorAccount $mca): bool
    {
        $connector = $mca->connector_name;

        if ($connector === ConnectorName::Test->value) {
            return true;
        }

        if ($connector === ConnectorName::Stripe->value) {
            $signature = $request->header('Stripe-Signature');
            if (! $signature) {
                return false;
            }
            $credentials = $mca->connector_account_details;
            $webhookSecret = $credentials['webhook_secret'] ?? null;
            if (! $webhookSecret) {
                Log::warning("Stripe webhook secret not configured for MCA {$mca->key}");

                return false;
            }

            return $this->verifyStripeSignature($request->getContent(), $signature, $webhookSecret);
        }

        if ($connector === ConnectorName::CloudPayments->value) {
            Log::warning("CloudPayments webhook signature verification not implemented for MCA {$mca->key}");

            return ! app()->environment('production');
        }

        Log::warning("Unknown connector for webhook verification: {$connector}");

        return false;
    }

    private function verifyStripeSignature(string $payload, string $signatureHeader, string $secret): bool
    {
        $elements = explode(',', $signatureHeader);
        $timestamp = null;
        $signatures = [];

        foreach ($elements as $element) {
            if (! str_contains($element, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $element, 2);
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if (! $timestamp || empty($signatures)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $signedPayload = $timestamp.'.'.$payload;
        $expectedSignature = hash_hmac('sha256', $signedPayload, $secret);

        foreach ($signatures as $sig) {
            if (hash_equals($expectedSignature, $sig)) {
                return true;
            }
        }

        return false;
    }

    private function processWebhook(MerchantConnectorAccount $mca, array $payload): void
    {
        $paymentId = $payload['data']['object']['metadata']['payment_id'] ?? null;
        if (! $paymentId) {
            return;
        }

        $payment = $this->paymentRepository->findByKeyOrNull($paymentId, $mca->merchant_account_id);
        if (! $payment) {
            return;
        }

        $newStatus = $this->mapPspEventToStatus($mca->connector_name, $payload['type'] ?? '');
        if (! $newStatus || ! PaymentStateMachine::canTransition($payment->status, $newStatus)) {
            return;
        }

        $result = DB::transaction(function () use ($payment, $newStatus) {
            $lockedPayment = $this->paymentRepository->findByIdLocked($payment->id);
            if (! $lockedPayment) {
                return null;
            }
            $previousStatus = $lockedPayment->status->value;

            if (PaymentStateMachine::canTransition($lockedPayment->status, $newStatus)) {
                $updateData = ['status' => $newStatus];
                if ($newStatus === PaymentStatus::Succeeded) {
                    $updateData['amount_received'] = $lockedPayment->amount;
                }
                $this->paymentRepository->update($lockedPayment, $updateData);

                return ['payment' => $lockedPayment->fresh(), 'previousStatus' => $previousStatus];
            }

            return null;
        });

        if ($result) {
            event(new PaymentStatusChanged($result['payment'], $result['previousStatus']));
        }
    }

    private function mapPspEventToStatus(string $connector, string $eventType): ?PaymentStatus
    {
        if ($connector === ConnectorName::Stripe->value) {
            return match ($eventType) {
                'payment_intent.succeeded' => PaymentStatus::Succeeded,
                'payment_intent.payment_failed' => PaymentStatus::Failed,
                'payment_intent.canceled' => PaymentStatus::Cancelled,
                'payment_intent.requires_action' => PaymentStatus::RequiresCustomerAction,
                default => null,
            };
        }

        if ($connector === ConnectorName::CloudPayments->value) {
            return match ($eventType) {
                'payment.succeeded' => PaymentStatus::Succeeded,
                'payment.canceled' => PaymentStatus::Cancelled,
                'payment.waiting_for_capture' => PaymentStatus::RequiresCapture,
                default => null,
            };
        }

        return null;
    }
}
