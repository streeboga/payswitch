<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\Payment\ConfirmPaymentData;
use App\DataTransferObjects\Payment\CreatePaymentData;
use App\Events\PaymentStatusChanged;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use App\Repositories\Contracts\PaymentIntentRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Streeboga\PaymentConnectors\ConnectorFactory;
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
        private CustomerRepositoryInterface $customerRepository,
        private PaymentConfirmationService $confirmationService,
    ) {}

    public function create(CreatePaymentData $dto, int|string $merchantAccountId): PaymentIntent
    {
        if ($dto->payment_id) {
            $existing = $this->paymentRepository->findByKeyOrNull($dto->payment_id, $merchantAccountId);
            if ($existing) {
                return $existing;
            }
        }

        if ($dto->customer_id) {
            $customer = $this->customerRepository->findByKeyOrNull($dto->customer_id, $merchantAccountId);
            if (! $customer) {
                throw new PaymentException('Customer not found', 'customer_not_found', 'invalid_request_error', 400);
            }
        }

        $expiry = $dto->session_expiry ?? (int) config('payswitch.payment.session_expiry', 900);

        $businessProfileId = $this->resolveBusinessProfileId($dto->profile_id, $merchantAccountId);

        return $this->paymentRepository->create([
            'merchant_account_id' => $merchantAccountId,
            'business_profile_id' => $businessProfileId,
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

    private function resolveBusinessProfileId(?string $profileKey, int|string $merchantAccountId): int
    {
        if ($profileKey) {
            $profile = $this->merchantRepository->findProfileByKey($profileKey);
            if ($profile->merchant_account_id !== (int) $merchantAccountId) {
                throw new PaymentException('Business profile not found', 'profile_not_found', 'invalid_request_error', 400);
            }

            return $profile->id;
        }

        $defaultProfile = $this->merchantRepository->findProfileByMerchant($merchantAccountId);
        if (! $defaultProfile) {
            throw new PaymentException('No business profile configured for this merchant', 'no_profile', 'invalid_request_error', 400);
        }

        return $defaultProfile->id;
    }

    public function find(string $paymentKey, int|string $merchantAccountId): PaymentIntent
    {
        return $this->paymentRepository->findByKey($paymentKey, $merchantAccountId);
    }

    /**
     * Get unique payment methods available for a payment's business profile.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getAvailablePaymentMethods(PaymentIntent $payment, ?string $locale = null): Collection
    {
        $locale ??= 'en';

        return $this->merchantRepository
            ->getActiveConnectorsByMerchant($payment->merchant_account_id)
            ->where('business_profile_id', $payment->business_profile_id)
            ->whereNotNull('payment_methods_enabled')
            ->flatMap(function (MerchantConnectorAccount $mca) use ($locale): array {
                $displayConfig = $mca->display_config['payment_methods'] ?? [];
                $displayByMethod = collect($displayConfig)->keyBy('method');

                return array_map(
                    function (mixed $m) use ($displayByMethod, $locale, $mca): array {
                        $base = is_array($m) ? $m : ['payment_method' => $m];
                        $method = $base['payment_method'];
                        $display = $displayByMethod->get($method);

                        if ($display) {
                            if (isset($display['display_name'])) {
                                $base['display_name'] = is_array($display['display_name'])
                                    ? ($display['display_name'][$locale] ?? $display['display_name']['en'] ?? null)
                                    : $display['display_name'];
                            }
                            if (isset($display['icon_url'])) {
                                $base['icon_url'] = $display['icon_url'];
                            }
                        }

                        $base['mode'] = $mca->display_config['widget_mode'] ?? 'redirect';

                        return $base;
                    },
                    $mca->payment_methods_enabled ?? [],
                );
            })
            ->unique('payment_method')
            ->values();
    }

    public function confirm(string $paymentKey, ConfirmPaymentData $dto, int|string $merchantAccountId): PaymentIntent
    {
        return $this->confirmationService->confirm($paymentKey, $dto, $merchantAccountId);
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
                'currency' => $payment->currency,
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

            $payment->refresh();

            return ['payment' => $payment, 'previousStatus' => $previousStatus];
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
                            $connector->void(['transaction_id' => $lastAttempt->connector_transaction_id, 'payment_id' => $payment->key]);
                        } catch (\Throwable $e) {
                            Log::warning("Failed to void authorization on cancel: {$e->getMessage()}");
                        }
                    }
                }
            }

            PaymentStateMachine::assertTransition($payment->status, PaymentStatus::Cancelled);
            $this->paymentRepository->update($payment, ['status' => PaymentStatus::Cancelled]);

            $payment->refresh();

            return ['payment' => $payment, 'previousStatus' => $previousStatus];
        });

        $this->dispatchStatusChanged($result['payment'], $result['previousStatus']);

        return $result['payment'];
    }

    public function sync(string $paymentKey, int|string $merchantAccountId): PaymentIntent
    {
        $payment = $this->paymentRepository->findByKey($paymentKey, $merchantAccountId);

        $syncableStatuses = [
            PaymentStatus::RequiresCustomerAction,
            PaymentStatus::Processing,
        ];

        if (! in_array($payment->status, $syncableStatuses, true)) {
            throw new PaymentException('Payment is not in a syncable state', 'invalid_state', 'invalid_request_error', 400);
        }

        $lastAttempt = $this->paymentRepository->findLastAttemptWithTransaction($payment);
        if (! $lastAttempt) {
            throw new PaymentException('No transaction to sync', 'no_transaction', 'invalid_request_error', 400);
        }

        $mca = $this->merchantRepository->findConnectorByMerchantAndName($merchantAccountId, $lastAttempt->connector);
        if (! $mca) {
            throw new PaymentException('Connector not available', 'connector_unavailable', 'invalid_request_error', 502);
        }

        $connector = ConnectorFactory::resolve($mca);
        $result = $connector->getPaymentStatus(['transaction_id' => $lastAttempt->connector_transaction_id]);

        $pspStatus = $result['data']['status'] ?? null;
        $newStatus = $pspStatus ? $connector->mapPaymentStatusToInternal($pspStatus) : null;

        $previousStatus = $payment->status->value;

        if ($newStatus && $newStatus !== $payment->status) {
            // Some terminal statuses (e.g. succeeded) may not be directly reachable
            // from the current status. Transition through an intermediate status if needed.
            if (! PaymentStateMachine::canTransition($payment->status, $newStatus)) {
                $intermediate = PaymentStatus::Processing;
                if (PaymentStateMachine::canTransition($payment->status, $intermediate)
                    && PaymentStateMachine::canTransition($intermediate, $newStatus)) {
                    $this->paymentRepository->update($payment, ['status' => $intermediate]);
                    $payment->refresh();
                } else {
                    Log::warning("Sync: cannot transition payment {$payment->key} from {$payment->status->value} to target status");

                    return $payment;
                }
            }

            $updateData = ['status' => $newStatus];
            if ($newStatus === PaymentStatus::Succeeded) {
                $updateData['amount_received'] = $payment->amount;
            }
            $this->paymentRepository->update($payment, $updateData);
            $payment->refresh();

            $this->dispatchStatusChanged($payment, $previousStatus);
        }

        return $payment;
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
