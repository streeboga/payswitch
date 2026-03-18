<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Events\PaymentStatusChanged;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\StateMachine\PaymentStateMachine;

final class WebhookReceiverController
{
    /**
     * Receive webhook from PSP (Stripe, YooKassa, etc.)
     * Route: POST /api/v1/webhooks/{merchantKey}/{mcaKey}
     * No auth middleware — PSP sends directly.
     */
    public function handle(Request $request, string $merchantKey, string $mcaKey): JsonResponse
    {
        $mca = MerchantConnectorAccount::where('key', $mcaKey)->first();

        if (! $mca) {
            return response()->json(['status' => 'ignored', 'reason' => 'connector_not_found'], 404);
        }

        // TODO: Verify webhook signature from PSP (Stripe-Signature header, etc.)
        // For now, accept all incoming webhooks

        $payload = $request->all();

        Log::info("Incoming webhook from {$mca->connector_name}", [
            'mca_key' => $mcaKey,
            'payload_type' => $payload['type'] ?? 'unknown',
        ]);

        // Map PSP event to internal payment status update
        try {
            $this->processWebhook($mca, $payload);
        } catch (\Exception $e) {
            Log::error("Webhook processing failed: {$e->getMessage()}", [
                'mca_key' => $mcaKey,
                'exception' => $e,
            ]);

            // Return 200 anyway to prevent PSP from retrying
            return response()->json(['status' => 'error', 'message' => $e->getMessage()]);
        }

        return response()->json(['status' => 'ok']);
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

        DB::transaction(function () use ($payment, $newStatus) {
            $payment = PaymentIntent::where('id', $payment->id)->lockForUpdate()->first();

            if (PaymentStateMachine::canTransition($payment->status, $newStatus)) {
                $payment->update(['status' => $newStatus]);

                if ($newStatus === PaymentStatus::Succeeded) {
                    $payment->update(['amount_received' => $payment->amount]);
                }

                event(new PaymentStatusChanged($payment));
            }
        });
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

        // YooKassa event mapping
        if ($connector === 'yookassa') {
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
