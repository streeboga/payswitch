<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\PaymentStatusChanged;
use App\Services\RoutingService;
use Illuminate\Support\Facades\DB;
use Streeboga\PaymentConnectors\ConnectorFactory;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Exceptions\PaymentException;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\PaymentMethod;
use Streeboga\PaymentData\StateMachine\PaymentStateMachine;

final class PaymentService
{
    public function create(array $data, int|string $merchantAccountId): PaymentIntent
    {
        return PaymentIntent::create([
            'merchant_account_id' => $merchantAccountId,
            'amount' => $data['amount'],
            'currency' => strtoupper($data['currency']),
            'status' => PaymentStatus::RequiresPaymentMethod,
            'capture_method' => $data['capture_method'] ?? CaptureMethod::Automatic,
            'authentication_type' => $data['authentication_type'] ?? 'no_three_ds',
            'customer_id' => $data['customer_id'] ?? null,
            'description' => $data['description'] ?? null,
            'return_url' => $data['return_url'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'session_expiry' => $data['session_expiry'] ?? config('payswitch.payment.session_expiry'),
            'attempt_count' => 1,
            'expires_on' => now()->addSeconds($data['session_expiry'] ?? config('payswitch.payment.session_expiry')),
            'amount_capturable' => $data['amount'],
        ]);
    }

    public function find(string $paymentKey, int|string $merchantAccountId): PaymentIntent
    {
        return PaymentIntent::where('key', $paymentKey)
            ->where('merchant_account_id', $merchantAccountId)
            ->firstOrFail();
    }

    public function confirm(string $paymentKey, array $data, int|string $merchantAccountId): PaymentIntent
    {
        return DB::transaction(function () use ($paymentKey, $data, $merchantAccountId) {
            $payment = PaymentIntent::where('key', $paymentKey)
                ->where('merchant_account_id', $merchantAccountId)
                ->lockForUpdate()
                ->firstOrFail();

            // Check for saved payment method
            $paymentMethodId = $data['payment_method_data']['payment_method_id'] ?? null;
            if ($paymentMethodId) {
                $pm = PaymentMethod::where('key', $paymentMethodId)
                    ->where('merchant_account_id', $merchantAccountId)
                    ->firstOrFail();

                // Use the saved token
                $data['token'] = $pm->connector_token;
                $data['connector'] = $pm->connector_name;
            }

            // Resolve connector
            $routingService = app(RoutingService::class);
            $explicitConnector = $data['connector'] ?? null;
            $paymentMethod = $data['payment_method'] ?? null;
            $mca = $routingService->resolve($merchantAccountId, $explicitConnector, $paymentMethod, $payment->currency, $payment->amount);

            // Get connector instance
            $connector = ConnectorFactory::resolve($mca);

            // Call PSP
            $connectorParams = array_merge($data, [
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'description' => $payment->description,
            ]);

            if ($payment->capture_method === CaptureMethod::Manual) {
                $result = $connector->authorize($connectorParams);
            } else {
                $result = $connector->purchase($connectorParams);
            }

            // Create attempt record
            $payment->paymentAttempts()->create([
                'connector' => $mca->connector_name,
                'connector_transaction_id' => $result['transaction_id'] ?? null,
                'status' => $result['success'] ? 'succeeded' : 'failed',
                'amount' => $payment->amount,
                'error_code' => $result['success'] ? null : ($result['code'] ?? null),
                'error_message' => $result['success'] ? null : ($result['message'] ?? null),
            ]);

            if ($result['success']) {
                if ($payment->capture_method === CaptureMethod::Manual) {
                    PaymentStateMachine::assertTransition($payment->status, PaymentStatus::RequiresCapture);
                    $payment->update([
                        'status' => PaymentStatus::RequiresCapture,
                        'connector' => $mca->connector_name,
                    ]);
                } else {
                    PaymentStateMachine::assertTransition($payment->status, PaymentStatus::Succeeded);
                    $payment->update([
                        'status' => PaymentStatus::Succeeded,
                        'amount_received' => $payment->amount,
                        'connector' => $mca->connector_name,
                    ]);
                }
            } else {
                // Try fallback
                $fallbackMca = $routingService->fallback($merchantAccountId, [$mca->connector_name]);

                if ($fallbackMca) {
                    $fallbackConnector = ConnectorFactory::resolve($fallbackMca);
                    $fallbackResult = $payment->capture_method === CaptureMethod::Manual
                        ? $fallbackConnector->authorize($connectorParams)
                        : $fallbackConnector->purchase($connectorParams);

                    $payment->paymentAttempts()->create([
                        'connector' => $fallbackMca->connector_name,
                        'connector_transaction_id' => $fallbackResult['transaction_id'] ?? null,
                        'status' => $fallbackResult['success'] ? 'succeeded' : 'failed',
                        'amount' => $payment->amount,
                        'error_code' => $fallbackResult['success'] ? null : ($fallbackResult['code'] ?? null),
                        'error_message' => $fallbackResult['success'] ? null : ($fallbackResult['message'] ?? null),
                    ]);

                    $payment->increment('attempt_count');

                    if ($fallbackResult['success']) {
                        $newStatus = $payment->capture_method === CaptureMethod::Manual
                            ? PaymentStatus::RequiresCapture
                            : PaymentStatus::Succeeded;
                        PaymentStateMachine::assertTransition($payment->status, $newStatus);
                        $payment->update([
                            'status' => $newStatus,
                            'amount_received' => $newStatus === PaymentStatus::Succeeded ? $payment->amount : null,
                            'connector' => $fallbackMca->connector_name,
                        ]);
                    } else {
                        PaymentStateMachine::assertTransition($payment->status, PaymentStatus::Failed);
                        $payment->update([
                            'status' => PaymentStatus::Failed,
                            'error_code' => $fallbackResult['code'] ?? null,
                            'error_message' => $fallbackResult['message'] ?? null,
                            'connector' => $fallbackMca->connector_name,
                        ]);
                    }
                } else {
                    PaymentStateMachine::assertTransition($payment->status, PaymentStatus::Failed);
                    $payment->update([
                        'status' => PaymentStatus::Failed,
                        'error_code' => $result['code'] ?? null,
                        'error_message' => $result['message'] ?? null,
                        'connector' => $mca->connector_name,
                    ]);
                }
            }

            event(new PaymentStatusChanged($payment));

            return $payment->fresh();
        });
    }

    public function capture(string $paymentKey, int $amount, int|string $merchantAccountId): PaymentIntent
    {
        return DB::transaction(function () use ($paymentKey, $amount, $merchantAccountId) {
            $payment = PaymentIntent::where('key', $paymentKey)
                ->where('merchant_account_id', $merchantAccountId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($payment->status !== PaymentStatus::RequiresCapture) {
                throw new \Streeboga\PaymentData\Exceptions\InvalidStateTransitionException(
                    $payment->status->value,
                    PaymentStatus::Succeeded->value,
                );
            }

            if ($amount > $payment->amount_capturable) {
                throw new PaymentException(
                    "Capture amount ({$amount}) exceeds capturable amount ({$payment->amount_capturable})",
                    'amount_exceeds_capturable',
                    'payment_error'
                );
            }

            // Get the last successful attempt to find the connector
            $lastAttempt = $payment->paymentAttempts()->where('status', 'succeeded')->latest()->first();
            if ($lastAttempt) {
                $mca = MerchantConnectorAccount::where('merchant_account_id', $merchantAccountId)
                    ->where('connector_name', $lastAttempt->connector)
                    ->first();
                if ($mca) {
                    $connector = ConnectorFactory::resolve($mca);
                    $connector->capture([
                        'amount' => $amount,
                        'transaction_id' => $lastAttempt->connector_transaction_id,
                    ]);
                }
            }

            PaymentStateMachine::assertTransition($payment->status, PaymentStatus::Succeeded);
            $payment->update([
                'status' => PaymentStatus::Succeeded,
                'amount_received' => $amount,
                'amount_capturable' => 0,
            ]);

            event(new PaymentStatusChanged($payment));

            return $payment->fresh();
        });
    }

    public function cancel(string $paymentKey, int|string $merchantAccountId): PaymentIntent
    {
        return DB::transaction(function () use ($paymentKey, $merchantAccountId) {
            $payment = PaymentIntent::where('key', $paymentKey)
                ->where('merchant_account_id', $merchantAccountId)
                ->lockForUpdate()
                ->firstOrFail();

            PaymentStateMachine::assertTransition($payment->status, PaymentStatus::Cancelled);

            $payment->update(['status' => PaymentStatus::Cancelled]);
            event(new PaymentStatusChanged($payment));

            return $payment->fresh();
        });
    }
}
