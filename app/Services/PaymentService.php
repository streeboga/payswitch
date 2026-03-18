<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\Payment\ConfirmPaymentData;
use App\DataTransferObjects\Payment\CreatePaymentData;
use App\Enums\PaymentAttemptStatus;
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
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\StateMachine\PaymentStateMachine;

final readonly class PaymentService
{
    public function __construct(
        private PaymentIntentRepositoryInterface $paymentRepository,
        private MerchantRepositoryInterface $merchantRepository,
        private RoutingService $routingService,
    ) {}

    public function create(CreatePaymentData $dto, int|string $merchantAccountId): PaymentIntent
    {
        if ($dto->payment_id) {
            $existing = $this->paymentRepository->findByKeyOrNull($dto->payment_id, $merchantAccountId);
            if ($existing) {
                return $existing;
            }
        }

        $expiry = $dto->session_expiry ?? (int) config('payswitch.payment.session_expiry', 900);

        return $this->paymentRepository->create([
            'merchant_account_id' => $merchantAccountId,
            'amount' => $dto->amount,
            'currency' => strtoupper($dto->currency),
            'status' => PaymentStatus::RequiresPaymentMethod,
            'capture_method' => $dto->capture_method,
            'authentication_type' => $dto->authentication_type,
            'customer_id' => $dto->customer_id,
            'description' => $dto->description,
            'return_url' => $dto->return_url,
            'metadata' => $dto->metadata,
            'session_expiry' => $expiry,
            'attempt_count' => 1,
            'expires_on' => now()->addSeconds($expiry),
            'amount_capturable' => $dto->amount,
        ]);
    }

    public function find(string $paymentKey, int|string $merchantAccountId): PaymentIntent
    {
        return $this->paymentRepository->findByKey($paymentKey, $merchantAccountId);
    }

    public function confirm(string $paymentKey, ConfirmPaymentData $dto, int|string $merchantAccountId): PaymentIntent
    {
        $result = DB::transaction(function () use ($paymentKey, $dto, $merchantAccountId) {
            $payment = $this->paymentRepository->findByKeyLocked($paymentKey, $merchantAccountId);
            $previousStatus = $payment->status->value;

            if ($payment->expires_on && $payment->expires_on->isPast()) {
                if (PaymentStateMachine::canTransition($payment->status, PaymentStatus::Expired)) {
                    $this->paymentRepository->update($payment, ['status' => PaymentStatus::Expired]);
                }
                throw new PaymentException('Payment session has expired', 'payment_expired', 'invalid_request_error', 400);
            }

            if (! in_array($payment->status, [PaymentStatus::RequiresPaymentMethod, PaymentStatus::RequiresConfirmation])) {
                throw new PaymentException(
                    "Payment cannot be confirmed in status '{$payment->status->value}'",
                    'invalid_state_transition',
                    'invalid_request_error',
                    400,
                );
            }

            $token = null;
            $connectorOverride = null;

            $paymentMethodId = $dto->payment_method_id ?? ($dto->payment_method_data['payment_method_id'] ?? null);
            if ($paymentMethodId) {
                $pm = $this->paymentRepository->findPaymentMethodByKey($paymentMethodId, $merchantAccountId);
                if (! $pm) {
                    throw new PaymentException('Payment method not found', 'payment_method_not_found', 'invalid_request_error', 404);
                }
                if (empty($pm->connector_token)) {
                    throw new PaymentException('Payment method token is invalid or expired', 'invalid_payment_method_token', 'invalid_request_error', 400);
                }
                $token = $pm->connector_token;
                $connectorOverride = $pm->connector_name;
            }

            $explicitConnector = $connectorOverride ?? $dto->connector;
            $mca = $this->routingService->resolve($merchantAccountId, $explicitConnector, $dto->payment_method, $payment->currency, $payment->amount);

            $connectorParams = [
                'payment_method' => $dto->payment_method,
                'payment_method_data' => $dto->payment_method_data,
                'token' => $token,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'description' => $payment->description,
            ];

            $result = $this->executeConnectorCall($payment, $mca, $connectorParams);

            if ($result['success']) {
                $this->applySuccessStatus($payment, $mca->connector_name);
            } else {
                $fallbackMca = $this->routingService->fallback($merchantAccountId, [$mca->connector_name]);

                if ($fallbackMca) {
                    $fallbackResult = $this->executeConnectorCall($payment, $fallbackMca, $connectorParams);

                    if ($fallbackResult['success']) {
                        $this->applySuccessStatus($payment, $fallbackMca->connector_name);
                    } else {
                        $this->applyFailedStatus($payment, $fallbackMca->connector_name, $fallbackResult);
                    }
                } else {
                    $this->applyFailedStatus($payment, $mca->connector_name, $result);
                }
            }

            return ['payment' => $payment->fresh(), 'previousStatus' => $previousStatus];
        });

        $this->dispatchStatusChanged($result['payment'], $result['previousStatus']);

        return $result['payment'];
    }

    public function capture(string $paymentKey, int $amount, int|string $merchantAccountId): PaymentIntent
    {
        $result = DB::transaction(function () use ($paymentKey, $amount, $merchantAccountId) {
            $payment = $this->paymentRepository->findByKeyLocked($paymentKey, $merchantAccountId);
            $previousStatus = $payment->status->value;

            if (! in_array($payment->status, [PaymentStatus::RequiresCapture, PaymentStatus::PartiallyCapturedAndCapturable], true)) {
                throw new InvalidStateTransitionException($payment->status->value, PaymentStatus::Succeeded->value);
            }

            if ($amount <= 0) {
                throw new PaymentException('Capture amount must be positive', 'invalid_amount', 'invalid_request_error', 400);
            }

            if ($amount > $payment->amount_capturable) {
                throw new PaymentException(
                    "Capture amount ({$amount}) exceeds capturable amount ({$payment->amount_capturable})",
                    'amount_exceeds_capturable',
                    'invalid_request_error',
                    400,
                );
            }

            $lastAttempt = $this->paymentRepository->findLastSuccessfulAttempt($payment);
            if (! $lastAttempt) {
                throw new PaymentException('No successful payment attempt found for capture', 'no_attempt', 'invalid_request_error', 400);
            }

            if (! $lastAttempt->connector_transaction_id) {
                throw new PaymentException('Missing transaction ID for capture', 'missing_transaction_id', 'invalid_request_error', 500);
            }

            $mca = $this->merchantRepository->findConnectorByMerchantAndName($merchantAccountId, $lastAttempt->connector);
            if (! $mca) {
                throw new PaymentException('Connector not found for capture', 'connector_not_found', 'invalid_request_error', 400);
            }

            $connector = ConnectorFactory::resolve($mca);
            $result = $connector->capture([
                'amount' => $amount,
                'transaction_id' => $lastAttempt->connector_transaction_id,
            ]);

            if (! $result['success']) {
                throw new PaymentException($result['message'] ?? 'Capture failed at connector', 'capture_failed', 'connector_error', 502);
            }

            $remaining = $payment->amount_capturable - $amount;
            $newStatus = $remaining > 0
                ? PaymentStatus::PartiallyCapturedAndCapturable
                : PaymentStatus::Succeeded;

            PaymentStateMachine::assertTransition($payment->status, $newStatus);
            $this->paymentRepository->update($payment, [
                'status' => $newStatus,
                'amount_received' => ($payment->amount_received ?? 0) + $amount,
                'amount_capturable' => $remaining,
            ]);

            return ['payment' => $payment->fresh(), 'previousStatus' => $previousStatus];
        });

        $this->dispatchStatusChanged($result['payment'], $result['previousStatus']);

        return $result['payment'];
    }

    public function cancel(string $paymentKey, int|string $merchantAccountId): PaymentIntent
    {
        $result = DB::transaction(function () use ($paymentKey, $merchantAccountId) {
            $payment = $this->paymentRepository->findByKeyLocked($paymentKey, $merchantAccountId);
            $previousStatus = $payment->status->value;

            if ($payment->status === PaymentStatus::RequiresCapture && $payment->connector) {
                $lastAttempt = $this->paymentRepository->findLastSuccessfulAttempt($payment);
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
            $this->paymentRepository->update($payment, ['status' => PaymentStatus::Cancelled]);

            return ['payment' => $payment->fresh(), 'previousStatus' => $previousStatus];
        });

        $this->dispatchStatusChanged($result['payment'], $result['previousStatus']);

        return $result['payment'];
    }

    /**
     * @return array{success: bool, message?: string, code?: string, transaction_id?: string}
     */
    private function executeConnectorCall(PaymentIntent $payment, MerchantConnectorAccount $mca, array $connectorParams): array
    {
        $connector = ConnectorFactory::resolve($mca);

        try {
            $result = $payment->capture_method === CaptureMethod::Manual
                ? $connector->authorize($connectorParams)
                : $connector->purchase($connectorParams);
        } catch (\Throwable $e) {
            $this->paymentRepository->createAttempt($payment, [
                'connector' => $mca->connector_name,
                'status' => PaymentAttemptStatus::Failed->value,
                'amount' => $payment->amount,
                'error_code' => 'connector_exception',
                'error_message' => $e->getMessage(),
            ]);
            $this->paymentRepository->incrementAttemptCount($payment);

            return ['success' => false, 'message' => $e->getMessage(), 'code' => 'connector_exception'];
        }

        $this->paymentRepository->createAttempt($payment, [
            'connector' => $mca->connector_name,
            'connector_transaction_id' => $result['transaction_id'] ?? null,
            'status' => $result['success'] ? PaymentAttemptStatus::Succeeded->value : PaymentAttemptStatus::Failed->value,
            'amount' => $payment->amount,
            'error_code' => $result['success'] ? null : ($result['code'] ?? null),
            'error_message' => $result['success'] ? null : ($result['message'] ?? null),
        ]);
        $this->paymentRepository->incrementAttemptCount($payment);

        return $result;
    }

    private function applySuccessStatus(PaymentIntent $payment, string $connectorName): void
    {
        $newStatus = $payment->capture_method === CaptureMethod::Manual
            ? PaymentStatus::RequiresCapture
            : PaymentStatus::Succeeded;

        PaymentStateMachine::assertTransition($payment->status, $newStatus);
        $this->paymentRepository->update($payment, [
            'status' => $newStatus,
            'amount_received' => $newStatus === PaymentStatus::Succeeded ? $payment->amount : null,
            'connector' => $connectorName,
        ]);
    }

    private function applyFailedStatus(PaymentIntent $payment, string $connectorName, array $result): void
    {
        PaymentStateMachine::assertTransition($payment->status, PaymentStatus::Failed);
        $this->paymentRepository->update($payment, [
            'status' => PaymentStatus::Failed,
            'error_code' => $result['code'] ?? null,
            'error_message' => $result['message'] ?? null,
            'connector' => $connectorName,
        ]);
    }

    private function dispatchStatusChanged(PaymentIntent $payment, string $previousStatus): void
    {
        try {
            event(new PaymentStatusChanged($payment, $previousStatus));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
