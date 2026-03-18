<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\Payment\ConfirmPaymentData;
use App\Enums\PaymentAttemptStatus;
use App\Events\PaymentStatusChanged;
use App\Repositories\Contracts\PaymentIntentRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Streeboga\PaymentConnectors\ConnectorFactory;
use Streeboga\PaymentData\Enums\AuthenticationType;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Exceptions\PaymentException;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\StateMachine\PaymentStateMachine;

final readonly class PaymentConfirmationService
{
    public function __construct(
        private PaymentIntentRepositoryInterface $paymentRepository,
        private RoutingService $routingService,
    ) {}

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
            } elseif (($result['code'] ?? null) === 'requires_action') {
                $this->applyRequiresActionStatus($payment, $mca->connector_name, $result);
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

            $payment->refresh();

            return ['payment' => $payment, 'previousStatus' => $previousStatus];
        });

        $this->dispatchStatusChanged($result['payment'], $result['previousStatus']);

        return $result['payment'];
    }

    /**
     * @param  array<string, mixed>  $connectorParams
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

        $attemptStatus = match (true) {
            $result['success'] => PaymentAttemptStatus::Succeeded,
            ($result['code'] ?? null) === 'requires_action' => PaymentAttemptStatus::RequiresAction,
            default => PaymentAttemptStatus::Failed,
        };

        $this->paymentRepository->createAttempt($payment, [
            'connector' => $mca->connector_name,
            'connector_transaction_id' => $result['transaction_id'] ?? null,
            'status' => $attemptStatus->value,
            'amount' => $payment->amount,
            'error_code' => $attemptStatus === PaymentAttemptStatus::Succeeded ? null : ($result['code'] ?? null),
            'error_message' => $attemptStatus === PaymentAttemptStatus::Succeeded ? null : ($result['message'] ?? null),
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

    /**
     * @param  array<string, mixed>  $result
     */
    private function applyRequiresActionStatus(PaymentIntent $payment, string $connectorName, array $result): void
    {
        PaymentStateMachine::assertTransition($payment->status, PaymentStatus::RequiresCustomerAction);
        $this->paymentRepository->update($payment, [
            'status' => PaymentStatus::RequiresCustomerAction,
            'authentication_type' => AuthenticationType::ThreeDs,
            'connector' => $connectorName,
            'metadata' => array_merge($payment->metadata ?? [], [
                'redirect_url' => $result['data']['redirect_url'] ?? null,
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
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
