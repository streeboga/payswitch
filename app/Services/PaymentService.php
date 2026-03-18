<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\PaymentStatusChanged;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use App\Repositories\Contracts\PaymentIntentRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Streeboga\PaymentConnectors\ConnectorFactory;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Exceptions\InvalidStateTransitionException;
use Streeboga\PaymentData\Exceptions\PaymentException;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\PaymentMethod;
use Streeboga\PaymentData\StateMachine\PaymentStateMachine;

final class PaymentService
{
    public function __construct(
        private PaymentIntentRepositoryInterface $paymentRepository,
        private MerchantRepositoryInterface $merchantRepository,
    ) {}

    public function create(array $data, int|string $merchantAccountId): PaymentIntent
    {
        if (isset($data['payment_id'])) {
            $existing = $this->paymentRepository->findByKeyOrNull($data['payment_id'], $merchantAccountId);
            if ($existing) {
                return $existing; // idempotent — return existing payment
            }
        }

        if (! isset($data['amount']) || ! is_int($data['amount']) || $data['amount'] <= 0) {
            throw new PaymentException('Amount must be a positive integer', 'invalid_amount', 'invalid_request_error', 400);
        }
        if (empty($data['currency']) || ! preg_match('/^[A-Z]{3}$/i', $data['currency'])) {
            throw new PaymentException('Currency must be a valid 3-letter ISO code', 'invalid_currency', 'invalid_request_error', 400);
        }
        $expiry = (int) ($data['session_expiry'] ?? config('payswitch.payment.session_expiry', 900));
        if ($expiry <= 0) {
            throw new PaymentException('Session expiry must be positive', 'invalid_session_expiry', 'invalid_request_error', 400);
        }

        return $this->paymentRepository->create([
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
            'session_expiry' => $expiry,
            'attempt_count' => 1,
            'expires_on' => now()->addSeconds($expiry),
            'amount_capturable' => $data['amount'],
        ]);
    }

    public function find(string $paymentKey, int|string $merchantAccountId): PaymentIntent
    {
        return $this->paymentRepository->findByKey($paymentKey, $merchantAccountId);
    }

    public function confirm(string $paymentKey, array $data, int|string $merchantAccountId): PaymentIntent
    {
        $result = DB::transaction(function () use ($paymentKey, $data, $merchantAccountId) {
            $payment = $this->paymentRepository->findByKeyLocked($paymentKey, $merchantAccountId);

            $previousStatus = $payment->status->value;

            if ($payment->expires_on && $payment->expires_on->isPast()) {
                // Transition to expired
                if (PaymentStateMachine::canTransition($payment->status, PaymentStatus::Expired)) {
                    $payment->update(['status' => PaymentStatus::Expired]);
                }
                throw new PaymentException('Payment session has expired', 'payment_expired', 'invalid_request_error', 400);
            }

            // Check payment is in confirmable state BEFORE calling PSP
            if (! in_array($payment->status, [PaymentStatus::RequiresPaymentMethod, PaymentStatus::RequiresConfirmation])) {
                throw new PaymentException(
                    "Payment cannot be confirmed in status '{$payment->status->value}'",
                    'invalid_state_transition',
                    'invalid_request_error',
                    400,
                );
            }

            // Check for saved payment method
            $paymentMethodId = $data['payment_method_data']['payment_method_id'] ?? null;
            if ($paymentMethodId) {
                $pm = PaymentMethod::where('key', $paymentMethodId)
                    ->where('merchant_account_id', $merchantAccountId)
                    ->firstOrFail();

                if ($pm && empty($pm->connector_token)) {
                    throw new PaymentException('Payment method token is invalid or expired', 'invalid_payment_method_token', 'invalid_request_error', 400);
                }

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

            try {
                $result = $payment->capture_method === CaptureMethod::Manual
                    ? $connector->authorize($connectorParams)
                    : $connector->purchase($connectorParams);
            } catch (\Throwable $e) {
                // Record failed attempt
                $payment->paymentAttempts()->create([
                    'connector' => $mca->connector_name,
                    'status' => 'failed',
                    'amount' => $payment->amount,
                    'error_code' => 'connector_exception',
                    'error_message' => $e->getMessage(),
                ]);
                // Try fallback
                $result = ['success' => false, 'message' => $e->getMessage(), 'code' => 'connector_exception'];
            }

            // Create attempt record
            if (($result['code'] ?? null) !== 'connector_exception') {
                $payment->paymentAttempts()->create([
                    'connector' => $mca->connector_name,
                    'connector_transaction_id' => $result['transaction_id'] ?? null,
                    'status' => $result['success'] ? 'succeeded' : 'failed',
                    'amount' => $payment->amount,
                    'error_code' => $result['success'] ? null : ($result['code'] ?? null),
                    'error_message' => $result['success'] ? null : ($result['message'] ?? null),
                ]);
            }

            $payment->increment('attempt_count');

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
                    try {
                        $fallbackResult = $payment->capture_method === CaptureMethod::Manual
                            ? $fallbackConnector->authorize($connectorParams)
                            : $fallbackConnector->purchase($connectorParams);
                    } catch (\Throwable $e) {
                        $fallbackResult = ['success' => false, 'message' => $e->getMessage(), 'code' => 'connector_exception'];
                    }

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

            return ['payment' => $payment->fresh(), 'previousStatus' => $previousStatus];
        });

        try {
            event(new PaymentStatusChanged($result['payment'], $result['previousStatus']));
        } catch (\Throwable $e) {
            report($e);
        }

        return $result['payment'];
    }

    public function capture(string $paymentKey, int $amount, int|string $merchantAccountId): PaymentIntent
    {
        $result = DB::transaction(function () use ($paymentKey, $amount, $merchantAccountId) {
            $payment = $this->paymentRepository->findByKeyLocked($paymentKey, $merchantAccountId);

            $previousStatus = $payment->status->value;

            if (! in_array($payment->status, [PaymentStatus::RequiresCapture, PaymentStatus::PartiallyCapturedAndCapturable], true)) {
                throw new InvalidStateTransitionException(
                    $payment->status->value,
                    PaymentStatus::Succeeded->value,
                );
            }

            if ($amount <= 0) {
                throw new PaymentException(
                    'Capture amount must be positive',
                    'invalid_amount',
                    'invalid_request_error',
                    400,
                );
            }

            if ($amount > $payment->amount_capturable) {
                throw new PaymentException(
                    "Capture amount ({$amount}) exceeds capturable amount ({$payment->amount_capturable})",
                    'amount_exceeds_capturable',
                    'invalid_request_error',
                    400,
                );
            }

            // Find connector from last successful attempt
            $lastAttempt = $payment->paymentAttempts()->where('status', 'succeeded')->latest()->first();
            if (! $lastAttempt) {
                throw new PaymentException(
                    'No successful payment attempt found for capture',
                    'no_attempt',
                    'invalid_request_error',
                    400,
                );
            }

            if (! $lastAttempt->connector_transaction_id) {
                throw new PaymentException('Missing transaction ID for capture', 'missing_transaction_id', 'invalid_request_error', 500);
            }

            $mca = $this->merchantRepository->findConnectorByMerchantAndName($merchantAccountId, $lastAttempt->connector);

            if (! $mca) {
                throw new PaymentException(
                    'Connector not found for capture',
                    'connector_not_found',
                    'invalid_request_error',
                    400,
                );
            }

            $connector = ConnectorFactory::resolve($mca);
            $result = $connector->capture([
                'amount' => $amount,
                'transaction_id' => $lastAttempt->connector_transaction_id,
            ]);

            if (! $result['success']) {
                throw new PaymentException(
                    $result['message'] ?? 'Capture failed at connector',
                    'capture_failed',
                    'connector_error',
                    502,
                );
            }

            $remaining = $payment->amount_capturable - $amount;
            if ($remaining > 0) {
                // Partial capture
                PaymentStateMachine::assertTransition($payment->status, PaymentStatus::PartiallyCapturedAndCapturable);
                $payment->update([
                    'status' => PaymentStatus::PartiallyCapturedAndCapturable,
                    'amount_received' => ($payment->amount_received ?? 0) + $amount,
                    'amount_capturable' => $remaining,
                ]);
            } else {
                // Full capture
                PaymentStateMachine::assertTransition($payment->status, PaymentStatus::Succeeded);
                $payment->update([
                    'status' => PaymentStatus::Succeeded,
                    'amount_received' => ($payment->amount_received ?? 0) + $amount,
                    'amount_capturable' => 0,
                ]);
            }

            return ['payment' => $payment->fresh(), 'previousStatus' => $previousStatus];
        });

        try {
            event(new PaymentStatusChanged($result['payment'], $result['previousStatus']));
        } catch (\Throwable $e) {
            report($e);
        }

        return $result['payment'];
    }

    public function cancel(string $paymentKey, int|string $merchantAccountId): PaymentIntent
    {
        $result = DB::transaction(function () use ($paymentKey, $merchantAccountId) {
            $payment = $this->paymentRepository->findByKeyLocked($paymentKey, $merchantAccountId);

            $previousStatus = $payment->status->value;

            if ($payment->status === PaymentStatus::RequiresCapture && $payment->connector) {
                $lastAttempt = $payment->paymentAttempts()->where('status', 'succeeded')->latest()->first();
                if ($lastAttempt) {
                    $mca = $this->merchantRepository->findConnectorByMerchantAndName($merchantAccountId, $lastAttempt->connector);
                    if ($mca) {
                        try {
                            $connector = ConnectorFactory::resolve($mca);
                            $connector->refund(['amount' => $payment->amount, 'transaction_id' => $lastAttempt->connector_transaction_id]);
                        } catch (\Throwable $e) {
                            Log::warning("Failed to void authorization on cancel: {$e->getMessage()}");
                        }
                    }
                }
            }

            PaymentStateMachine::assertTransition($payment->status, PaymentStatus::Cancelled);

            $payment->update(['status' => PaymentStatus::Cancelled]);

            return ['payment' => $payment->fresh(), 'previousStatus' => $previousStatus];
        });

        try {
            event(new PaymentStatusChanged($result['payment'], $result['previousStatus']));
        } catch (\Throwable $e) {
            report($e);
        }

        return $result['payment'];
    }
}
