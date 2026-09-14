<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\Payment\ConfirmPaymentData;
use App\DataTransferObjects\Payment\CreatePaymentData;
use App\Events\PaymentStatusChanged;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use App\Repositories\Contracts\PaymentIntentRepositoryInterface;
use Illuminate\Database\UniqueConstraintViolationException;
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

    /**
     * Повтор с тем же Idempotency-Key у того же мерчанта отдаёт уже созданный платёж
     * (у него wasRecentlyCreated === false — по этому контроллер понимает, что это повтор).
     * Тот же ключ с другой суммой или валютой — 422 idempotency_key_reused.
     */
    public function create(CreatePaymentData $dto, int|string $merchantAccountId): PaymentIntent
    {
        if ($dto->idempotency_key !== null) {
            $existing = $this->paymentRepository->findByIdempotencyKey($dto->idempotency_key, $merchantAccountId);
            if ($existing) {
                return $this->replayed($existing, $dto);
            }
        }

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

        $attributes = [
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
            'idempotency_key' => $dto->idempotency_key,
        ];

        try {
            // Своя транзакция, а внутри чужой — точка сохранения: на Postgres упавший INSERT
            // иначе делает внешнюю транзакцию непригодной, и поиск ниже падает.
            return DB::transaction(fn () => $this->paymentRepository->create($attributes));
        } catch (UniqueConstraintViolationException $e) {
            // Гонка двух запросов с одним ключом: между нашим поиском и вставкой успел
            // вставить другой. Отдаём его платёж.
            $existing = $dto->idempotency_key !== null
                ? $this->paymentRepository->findByIdempotencyKey($dto->idempotency_key, $merchantAccountId)
                : null;

            if (! $existing) {
                throw $e;
            }

            return $this->replayed($existing, $dto);
        }
    }

    private function replayed(PaymentIntent $existing, CreatePaymentData $dto): PaymentIntent
    {
        if ($existing->amount !== $dto->amount || $existing->currency !== strtoupper($dto->currency)) {
            throw new PaymentException(
                'Idempotency-Key was already used with different parameters',
                'idempotency_key_reused',
                'invalid_request_error',
                422,
            );
        }

        return $existing;
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
     * Get available payment methods with smart method/connector resolution.
     *
     * @return array{mode: string, methods: list<array<string, mixed>>, connectors: list<array<string, mixed>>}
     */
    public function getAvailablePaymentMethods(PaymentIntent $payment, ?string $locale = null): array
    {
        $locale ??= 'en';

        $connectors = $this->merchantRepository
            ->getActiveConnectorsByMerchant($payment->merchant_account_id)
            ->where('business_profile_id', $payment->business_profile_id)
            ->whereNotNull('payment_methods_enabled');

        // If payment is pinned to a specific connector, show only that one
        if ($payment->connector) {
            $connectors = $connectors->where('connector_name', $payment->connector);
        }

        $methods = [];
        $connectorEntries = [];
        $seenMethods = [];

        foreach ($connectors as $mca) {
            /** @var MerchantConnectorAccount $mca */
            $driverClass = ConnectorFactory::resolveClass($mca->connector_name);

            if (! $driverClass) {
                continue;
            }

            $capabilities = $driverClass::capabilities();
            $enabledMethods = $this->normalizeEnabledMethods($mca->payment_methods_enabled ?? []);
            $hasAnyDirectMethod = false;

            foreach ($enabledMethods as $method) {
                if ($capabilities->supportsDirectMethod($method)) {
                    $hasAnyDirectMethod = true;

                    // First connector wins for each method
                    if (isset($seenMethods[$method])) {
                        continue;
                    }

                    $seenMethods[$method] = true;
                    $directMethod = $capabilities->getDirectMethod($method);

                    $methods[] = [
                        'method' => $method,
                        'display_name' => $this->getMethodDisplayName($method, $locale),
                        'type' => 'direct',
                        'connector' => $mca->connector_name,
                        'connector_key' => $mca->key,
                        'session_type' => $directMethod->sessionType->value,
                    ];
                }
            }

            // If no enabled methods have direct support, add as connector entry
            if (! $hasAnyDirectMethod) {
                $displayConfig = $mca->display_config ?? [];

                $connectorEntries[] = [
                    'connector_name' => $mca->connector_name,
                    'connector_key' => $mca->key,
                    'display_name' => $displayConfig['display_name'] ?? $capabilities->displayName($locale),
                    'logo_url' => self::logoUrl($displayConfig['logo_url'] ?? $capabilities->logoPath),
                    'session_type' => $capabilities->fallbackSessionType->value,
                ];
            }
        }

        $mode = match (true) {
            ! empty($methods) && ! empty($connectorEntries) => 'mixed',
            ! empty($methods) => 'direct_methods',
            ! empty($connectorEntries) => 'connector_selection',
            default => 'none',
        };

        // Single connector with no direct methods — skip connector selection, just show Pay button.
        // The widget will auto-send the connector key on confirm.
        $defaultConnector = null;
        if ($mode === 'connector_selection' && count($connectorEntries) === 1) {
            $mode = 'none';
            $defaultConnector = $connectorEntries[0]['connector_key'];
        }

        return [
            'mode' => $mode,
            'methods' => array_values($methods),
            'connectors' => array_values($connectorEntries),
            ...($defaultConnector ? ['default_connector' => $defaultConnector] : []),
        ];
    }

    /**
     * Абсолютный адрес логотипа коннектора — или ничего.
     *
     * Виджет живёт на странице мерчанта, поэтому путь вида `/logos/test.svg`
     * браузер разрешает от домена мерчанта, а не от нашего: картинка не
     * находится нигде. И отдавать адрес файла, которого у нас нет, тоже нельзя —
     * получится битая картинка вместо иконки. Нет файла — нет адреса, виджет
     * рисует свою общую иконку.
     */
    private static function logoUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        // Уже абсолютный — отдаём как есть: это осознанно настроенный адрес.
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return file_exists(public_path(ltrim($path, '/'))) ? url($path) : null;
    }

    /**
     * Normalize payment_methods_enabled to a flat list of method strings.
     *
     * Handles both ['card', 'sbp'] and [['payment_method' => 'card']] formats.
     *
     * @return list<string>
     */
    private function normalizeEnabledMethods(array $methods): array
    {
        return array_map(
            fn (mixed $m): string => is_array($m) ? ($m['payment_method'] ?? '') : (string) $m,
            $methods,
        );
    }

    /**
     * Get localized display name for a payment method.
     */
    private function getMethodDisplayName(string $method, string $locale): string
    {
        $names = [
            'card' => ['ru' => 'Банковская карта', 'en' => 'Card'],
            'sbp' => ['ru' => 'СБП', 'en' => 'SBP'],
            'bank_transfer' => ['ru' => 'Банковский перевод', 'en' => 'Bank Transfer'],
            'qiwi' => ['ru' => 'QIWI', 'en' => 'QIWI'],
            'yoomoney' => ['ru' => 'ЮMoney', 'en' => 'YooMoney'],
            'apple_pay' => ['ru' => 'Apple Pay', 'en' => 'Apple Pay'],
            'google_pay' => ['ru' => 'Google Pay', 'en' => 'Google Pay'],
        ];

        return $names[$method][$locale] ?? $names[$method]['en'] ?? ucfirst(str_replace('_', ' ', $method));
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
                        $this->voidAtConnector($payment, $mca, $lastAttempt->connector_transaction_id);
                    }
                }
            }

            // requires_customer_action с сессией у провайдера отменяется только у нас.
            // Ни один коннектор не умеет закрыть сессию через ConnectorInterface (void у них —
            // отмена авторизации: Stripe ждёт PaymentIntent, а в попытке лежит cs_…), так что
            // ссылка на оплату у провайдера остаётся оплачиваемой до своего истечения, а
            // пришедшая после отмены оплата в cancelled уже не переведётся. Кандидаты на
            // отдельный метод: T-Bank Cancel (NEW → CANCELED), Stripe checkout/sessions/{id}/expire,
            // RBS decline.do.

            PaymentStateMachine::assertTransition($payment->status, PaymentStatus::Cancelled);
            $this->paymentRepository->update($payment, ['status' => PaymentStatus::Cancelled]);

            $payment->refresh();

            return ['payment' => $payment, 'previousStatus' => $previousStatus];
        });

        $this->dispatchStatusChanged($result['payment'], $result['previousStatus']);

        return $result['payment'];
    }

    /**
     * Отмена холда у провайдера. Любая неудача — исключение, исключение, отказ или
     * неизвестный исход — не глотается: иначе платёж становился cancelled, а деньги
     * клиента оставались заблокированы. Транзакция отмены откатывается, клиенту 502.
     */
    private function voidAtConnector(PaymentIntent $payment, MerchantConnectorAccount $mca, ?string $transactionId): void
    {
        try {
            $result = ConnectorFactory::resolve($mca)->void(['transaction_id' => $transactionId, 'payment_id' => $payment->key]);
        } catch (\Throwable $e) {
            $result = ['success' => false, 'message' => $e->getMessage(), 'code' => 'connector_exception'];
        }

        if (($result['success'] ?? false) === true) {
            return;
        }

        Log::error('Void at connector failed, payment not cancelled', [
            'payment_id' => $payment->key,
            'connector' => $mca->connector_name,
            'code' => $result['code'] ?? null,
            'message' => $result['message'] ?? null,
        ]);

        throw new PaymentException(
            'Failed to void the authorization at the connector: '.($result['message'] ?? 'unknown error'),
            'void_failed',
            'connector_error',
            502,
        );
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

        if (! $newStatus || $newStatus === $payment->status) {
            return $payment;
        }

        // Пока шёл запрос к PSP, статус мог сменить вебхук. Решение — на
        // заблокированной строке, иначе sync затрёт `failed` на `succeeded`
        // или объявит тот же переход второй раз.
        $previousStatus = null;
        $payment = DB::transaction(function () use ($paymentKey, $merchantAccountId, $newStatus, $syncableStatuses, &$previousStatus) {
            $payment = $this->paymentRepository->findByKeyLocked($paymentKey, $merchantAccountId);

            if ($payment->status === $newStatus || ! in_array($payment->status, $syncableStatuses, true)) {
                return $payment;
            }

            $currentStatus = $payment->status;

            // Some terminal statuses (e.g. succeeded) may not be directly reachable
            // from the current status. Transition through an intermediate status if needed.
            if (! PaymentStateMachine::canTransition($payment->status, $newStatus)) {
                $intermediate = PaymentStatus::Processing;
                if (PaymentStateMachine::canTransition($payment->status, $intermediate)
                    && PaymentStateMachine::canTransition($intermediate, $newStatus)) {
                    $this->paymentRepository->update($payment, ['status' => $intermediate]);
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
            $previousStatus = $currentStatus->value;

            return $payment->refresh();
        });

        // Событие — после коммита и только если статус сменил этот вызов.
        if ($previousStatus !== null) {
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
