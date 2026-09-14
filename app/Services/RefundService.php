<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\Refund\CreateRefundData;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use App\Repositories\Contracts\PaymentIntentRepositoryInterface;
use App\Repositories\Contracts\RefundRepositoryInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Streeboga\PaymentConnectors\ConnectorErrorNormalizer;
use Streeboga\PaymentConnectors\ConnectorFactory;
use Streeboga\PaymentData\Contracts\ConnectorInterface;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Exceptions\PaymentException;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\Refund;

final readonly class RefundService
{
    public function __construct(
        private RefundRepositoryInterface $refundRepository,
        private PaymentIntentRepositoryInterface $paymentRepository,
        private MerchantRepositoryInterface $merchantRepository,
        private WebhookService $webhookService,
    ) {}

    /**
     * Возврат в три шага, чтобы исход у провайдера не терялся.
     *
     * 1. Транзакция с блокировкой платежа: проверка остатка (pending тоже держит сумму),
     *    вставка возврата в pending, коммит.
     * 2. Вызов провайдера — вне транзакции и блокировки.
     * 3. Транзакция с итоговым статусом и событием для мерчанта.
     *
     * Исход неизвестен (исключение, таймаут, неразобранный ответ, pending у провайдера) —
     * возврат остаётся pending, клиенту 502 refund_pending с ключом возврата в
     * errors[].meta.refund_id. Раньше неудача откатывала всё: провайдер мог вернуть
     * деньги, а записи не было, и повтор делал второй возврат.
     *
     * Повтор с тем же Idempotency-Key у того же мерчанта отдаёт уже созданный возврат
     * в текущем состоянии (wasRecentlyCreated === false) и к провайдеру не ходит. Тот же
     * ключ с другой суммой или по другому платежу — 422 idempotency_key_reused.
     */
    public function create(CreateRefundData $dto, int|string $merchantAccountId): Refund
    {
        try {
            $reserved = DB::transaction(fn () => $this->reserve($dto, $merchantAccountId));
        } catch (UniqueConstraintViolationException $e) {
            // Гонка одного ключа: по одному платежу её разводит блокировка, по разным
            // платежам — только уникальный индекс. Отдаём победителя.
            $existing = $dto->idempotency_key !== null
                ? $this->refundRepository->findByIdempotencyKey($dto->idempotency_key, $merchantAccountId)
                : null;

            if (! $existing) {
                throw $e;
            }

            return $this->replayed($existing, $dto);
        }

        if ($reserved instanceof Refund) {
            return $reserved;
        }

        ['refund' => $refund, 'payment' => $payment, 'connector' => $connector, 'transaction_id' => $transactionId] = $reserved;

        try {
            $result = $connector->refund([
                'payment_id' => $payment->key,
                'refund_id' => $refund->key,
                'amount' => $refund->amount,
                'currency' => $payment->currency,
                'transaction_id' => $transactionId,
            ]);
        } catch (\Throwable $e) {
            $result = ['success' => false, 'message' => $e->getMessage(), 'code' => 'connector_exception'];
        }

        if (ConnectorErrorNormalizer::isIndeterminate($result)) {
            if (! empty($result['transaction_id'])) {
                $this->refundRepository->updateRefund($refund, ['connector_refund_id' => $result['transaction_id']]);
            }

            Log::error('Refund outcome at connector is unknown, refund left pending', [
                'refund_id' => $refund->key,
                'payment_id' => $payment->key,
                'connector' => $refund->connector,
                'code' => $result['code'] ?? null,
                'message' => $result['message'] ?? null,
            ]);

            throw new PaymentException(
                'Refund outcome at the connector is unknown; the refund stays pending',
                'refund_pending',
                'connector_error',
                502,
                meta: ['refund_id' => $refund->key],
            );
        }

        $succeeded = $result['success'] === true;
        $status = $succeeded ? RefundStatus::Succeeded : RefundStatus::Failed;

        DB::transaction(function () use ($refund, $payment, $result, $succeeded, $status) {
            // Уведомление провайдера о возврате могло прийти раньше нашего ответа и
            // уже провести этот возврат (WebhookReceiverService::recordProviderRefund).
            // Второй раз не проводим и второй refund_succeeded не шлём. Модель не
            // подменяем: по wasRecentlyCreated контроллер отличает 201 от повтора.
            $locked = $this->refundRepository->findByIdLocked($refund->id);
            if ($locked && $locked->status !== RefundStatus::Pending) {
                $refund->refresh();

                return;
            }

            $this->refundRepository->updateRefund($refund, [
                'status' => $status,
                'connector_refund_id' => $result['transaction_id'] ?? null,
                'error_code' => $succeeded ? null : ($result['code'] ?? null),
                'error_message' => $succeeded ? null : ($result['message'] ?? null),
            ]);

            // Событие и строка очереди — в той же транзакции, что и статус:
            // очередь database пишет job тем же соединением. Исключение для
            // клиента бросается уже после коммита, поэтому refund_failed больше
            // не откатывается вместе со статусом.
            $this->webhookService->dispatchForRefund($refund->refresh(), $payment);
        });

        if (! $succeeded && $refund->status !== RefundStatus::Succeeded) {
            throw new PaymentException(
                $result['message'] ?? 'Refund failed at connector',
                'refund_failed',
                'connector_error',
                502,
                meta: ['refund_id' => $refund->key],
            );
        }

        return $refund;
    }

    private function replayed(Refund $existing, CreateRefundData $dto): Refund
    {
        if ($existing->amount !== $dto->amount || $existing->paymentIntent->key !== $dto->payment_id) {
            throw new PaymentException(
                'Idempotency-Key was already used with different parameters',
                'idempotency_key_reused',
                'invalid_request_error',
                422,
            );
        }

        return $existing;
    }

    /**
     * Шаг 1: под блокировкой платежа проверяет остаток и вставляет pending-возврат.
     * Возвращает Refund, если это повтор по ключу идемпотентности.
     *
     * @return Refund|array{refund: Refund, payment: PaymentIntent, connector: ConnectorInterface, transaction_id: ?string}
     */
    private function reserve(CreateRefundData $dto, int|string $merchantAccountId): Refund|array
    {
        $payment = $this->paymentRepository->findByKeyLocked($dto->payment_id, $merchantAccountId);

        // После блокировки: параллельный запрос с тем же ключом по этому платежу уже
        // закоммитил свой возврат, и мы его видим.
        if ($dto->idempotency_key !== null) {
            $existing = $this->refundRepository->findByIdempotencyKey($dto->idempotency_key, $merchantAccountId);
            if ($existing) {
                return $this->replayed($existing, $dto);
            }
        }

        if (! in_array($payment->status, [PaymentStatus::Succeeded, PaymentStatus::PartiallyCaptured, PaymentStatus::PartiallyCapturedAndCapturable])) {
            throw new PaymentException(
                'Payment must be in succeeded or partially captured status to refund',
                'payment_not_succeeded',
                'invalid_request_error',
                400,
            );
        }

        if ($dto->amount <= 0) {
            throw new PaymentException(
                'Refund amount must be positive',
                'invalid_amount',
                'invalid_request_error',
                400,
            );
        }

        $totalRefunded = $this->refundRepository->sumPendingAndSucceededForPayment($payment->id);

        if ($dto->amount > PHP_INT_MAX - $totalRefunded) {
            throw new PaymentException('Amount overflow', 'amount_overflow', 'invalid_request_error', 400);
        }

        if ($payment->amount_received < $dto->amount + $totalRefunded) {
            throw new PaymentException(
                "Refund amount ({$dto->amount}) plus already refunded ({$totalRefunded}) exceeds payment amount ({$payment->amount_received})",
                'refund_exceeds_payment',
                'invalid_request_error',
                400,
            );
        }

        // Resolve connector from last successful attempt
        $lastAttempt = $this->paymentRepository->findLastSuccessfulAttempt($payment);
        $connectorName = $payment->connector;

        if (! $lastAttempt || ! $connectorName) {
            throw new PaymentException('No successful payment attempt found for refund', 'missing_attempt', 'invalid_request_error', 400);
        }

        $mca = $this->merchantRepository->findConnectorByMerchantAndName($merchantAccountId, $connectorName);

        if (! $mca) {
            throw new PaymentException('Connector no longer available for refund', 'connector_unavailable', 'invalid_request_error', 502);
        }

        $refund = $this->refundRepository->create([
            'payment_intent_id' => $payment->id,
            'merchant_account_id' => $merchantAccountId,
            'amount' => $dto->amount,
            'currency' => $payment->currency,
            'status' => RefundStatus::Pending,
            'reason' => $dto->reason,
            'connector' => $connectorName,
            'metadata' => $dto->metadata,
            'idempotency_key' => $dto->idempotency_key,
        ]);

        return [
            'refund' => $refund,
            'payment' => $payment,
            'connector' => ConnectorFactory::resolve($mca),
            'transaction_id' => $lastAttempt->connector_transaction_id,
        ];
    }

    public function find(string $refundKey, int|string $merchantAccountId): Refund
    {
        return $this->refundRepository->findByKey($refundKey, $merchantAccountId);
    }
}
