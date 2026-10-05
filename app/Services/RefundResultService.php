<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Contracts\PaymentIntentRepositoryInterface;
use App\Repositories\Contracts\RefundRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Models\Refund;

/**
 * Итог возврата, о котором мы узнали не из ответа на свой запрос: уведомление
 * провайдера или сверка. Одна дверь — статус и событие мерчанту в одной транзакции.
 *
 * Раньше уведомление переводило pending-возврат в succeeded молча: клиент, получивший
 * 502 refund_pending, о деньгах так и не узнавал, пока сам не спросит.
 */
final readonly class RefundResultService
{
    public function __construct(
        private RefundRepositoryInterface $refunds,
        private PaymentIntentRepositoryInterface $payments,
        private WebhookService $webhookService,
    ) {}

    /**
     * @param  array{code?: string|null, message?: string|null}  $error
     * @return bool Перевёл ли этот вызов возврат в итоговый статус.
     */
    public function settle(Refund $refund, RefundStatus $status, ?string $connectorRefundId = null, array $error = []): bool
    {
        if (! in_array($status, [RefundStatus::Succeeded, RefundStatus::Failed], true)) {
            return false;
        }

        return DB::transaction(function () use ($refund, $status, $connectorRefundId, $error) {
            // Порядок блокировок как у приёма уведомления: платёж, затем возврат.
            $payment = $this->payments->findByIdLocked($refund->payment_intent_id);
            $locked = $this->refunds->findByIdLocked($refund->id);

            // Итог уже записан (ответ на запрос, повтор уведомления) — второй раз не шлём.
            if (! $payment || ! $locked || $locked->status !== RefundStatus::Pending) {
                return false;
            }

            $failed = $status === RefundStatus::Failed;
            $this->refunds->updateRefund($locked, [
                'status' => $status,
                'connector_refund_id' => $connectorRefundId ?? $locked->connector_refund_id,
                'error_code' => $failed ? ($error['code'] ?? null) : null,
                'error_message' => $failed ? ($error['message'] ?? null) : null,
            ]);

            $this->webhookService->dispatchForRefund($locked, $payment);

            return true;
        });
    }
}
