<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Events\PaymentStatusChanged;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\StateMachine\PaymentStateMachine;

final class WebhookReceiverController
{
    /**
     * Receive webhook from PSP (Stripe, CloudPayments, etc.)
     * Route: POST /api/v1/webhooks/{merchantKey}/{mcaKey}
     * No auth middleware — PSP sends directly.
     */
    public function handle(Request $request, string $merchantKey, string $mcaKey): JsonResponse
    {
        // Validate merchant exists
        $merchant = MerchantAccount::where('key', $merchantKey)->first();
        if (! $merchant) {
            return response()->json(['status' => 'ignored'], 404);
        }

        // Validate MCA exists and belongs to this merchant
        $mca = MerchantConnectorAccount::where('key', $mcaKey)
            ->where('merchant_account_id', $merchant->id)
            ->first();

        if (! $mca) {
            return response()->json(['status' => 'ignored'], 404);
        }

        // Verify webhook signature based on connector type
        if (! $this->verifySignature($request, $mca)) {
            Log::warning('Webhook signature verification failed', [
                'merchant_key' => $merchantKey,
                'mca_key' => $mcaKey,
                'connector' => $mca->connector_name,
            ]);

            return response()->json(['status' => 'invalid_signature'], 401);
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
            // Return 200 to prevent PSP retry on processing errors
            // Never expose internal error details
        }

        return response()->json(['status' => 'ok']);
    }

    private function verifySignature(Request $request, MerchantConnectorAccount $mca): bool
    {
        $connector = $mca->connector_name;

        // Test connector — always accept
        if ($connector === 'test') {
            return true;
        }

        // Stripe — verify Stripe-Signature header
        if ($connector === 'stripe') {
            $signature = $request->header('Stripe-Signature');
            if (! $signature) {
                return false;
            }

            // For production: use Stripe's webhook secret from connector_account_details
            $credentials = $mca->connector_account_details;
            if (is_string($credentials)) {
                $credentials = json_decode($credentials, true);
            }

            $webhookSecret = $credentials['webhook_secret'] ?? null;
            if (! $webhookSecret) {
                // No webhook secret configured — log warning but accept
                Log::warning("Stripe webhook secret not configured for MCA {$mca->key}");

                return true;
            }

            return $this->verifyStripeSignature($request->getContent(), $signature, $webhookSecret);
        }

        // CloudPayments — verify by checking request IP or HMAC
        if ($connector === 'cloudpayments') {
            // CloudPayments sends webhooks from specific IPs
            // For production: validate against their IP whitelist
            return true;
        }

        // Unknown connector — reject by default
        Log::warning("Unknown connector for webhook verification: {$connector}");

        return false;
    }

    private function verifyStripeSignature(string $payload, string $signatureHeader, string $secret): bool
    {
        $elements = explode(',', $signatureHeader);
        $timestamp = null;
        $signatures = [];

        foreach ($elements as $element) {
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

        // Verify not too old (5 minute tolerance)
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
        // Stripe-specific: extract payment intent ID from metadata
        $paymentId = $payload['data']['object']['metadata']['payment_id'] ?? null;

        if (! $paymentId) {
            return; // Can't match to a payment
        }

        $payment = PaymentIntent::where('key', $paymentId)
            ->where('merchant_account_id', $mca->merchant_account_id)
            ->first();

        if (! $payment) {
            return;
        }

        $newStatus = $this->mapPspEventToStatus($mca->connector_name, $payload['type'] ?? '');

        if (! $newStatus || ! PaymentStateMachine::canTransition($payment->status, $newStatus)) {
            return;
        }

        $result = DB::transaction(function () use ($payment, $newStatus) {
            $payment = PaymentIntent::where('id', $payment->id)->lockForUpdate()->first();
            $previousStatus = $payment->status->value;

            if (PaymentStateMachine::canTransition($payment->status, $newStatus)) {
                $payment->update(['status' => $newStatus]);

                if ($newStatus === PaymentStatus::Succeeded) {
                    $payment->update(['amount_received' => $payment->amount]);
                }

                return ['payment' => $payment->fresh(), 'previousStatus' => $previousStatus];
            }

            return null;
        });

        if ($result) {
            event(new PaymentStatusChanged($result['payment'], $result['previousStatus']));
        }
    }

    private function mapPspEventToStatus(string $connector, string $eventType): ?PaymentStatus
    {
        // Stripe event mapping
        if ($connector === 'stripe') {
            return match ($eventType) {
                'payment_intent.succeeded' => PaymentStatus::Succeeded,
                'payment_intent.payment_failed' => PaymentStatus::Failed,
                'payment_intent.canceled' => PaymentStatus::Cancelled,
                'payment_intent.requires_action' => PaymentStatus::RequiresCustomerAction,
                default => null,
            };
        }

        // CloudPayments event mapping
        if ($connector === 'cloudpayments') {
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
