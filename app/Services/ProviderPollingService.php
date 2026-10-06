<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\PaymentStatusChanged;
use App\Repositories\Contracts\PaymentIntentRepositoryInterface;
use App\Repositories\Contracts\RefundRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Streeboga\PaymentConnectors\Drivers\TestConnector;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\Refund;

/**
 * Опрос провайдера по расписанию: платёж, застрявший в processing или
 * requires_customer_action, и pending-возврат не ждут уведомления вечно.
 *
 * Платёж спрашивается тем же PaymentService::sync(), что и POST /payments/{id}/sync, —
 * переходы и события мерчанту обычные. Возврат — RefundReconciliationService (только
 * коннекторы с RefundStatusQueryable и только возвраты с id провайдера).
 */
final readonly class ProviderPollingService
{
    /**
     * Минуты ожидания: [0] — до первого опроса, [n] — после n-го опроса без итога.
     * Опросы приходятся на 1, 3, 8, 23, 83 минуты, дальше раз в 6 часов; после девятого
     * (≈ 25 часов) платёж получает конечный статус, возврат остаётся человеку.
     */
    public const array SCHEDULE = [1, 2, 5, 15, 60, 360, 360, 360, 360];

    /** error_code платежа, по которому провайдер за сутки так и не дал итога. */
    public const string UNCONFIRMED = 'provider_unconfirmed';

    private const array PAYMENT_STATUSES = [PaymentStatus::Processing, PaymentStatus::RequiresCustomerAction];

    public function __construct(
        private PaymentIntentRepositoryInterface $payments,
        private RefundRepositoryInterface $refunds,
        private PaymentService $paymentService,
        private RefundReconciliationService $refundReconciliation,
    ) {}

    /**
     * @param  int  $limit  Не больше стольких платежей и стольких возвратов за прогон.
     * @param  float  $deadline  microtime(true), после которого новый опрос не начинается.
     */
    public function run(int $limit, float $deadline): void
    {
        $firstBefore = now()->subMinutes(self::SCHEDULE[0]);
        // Симулятор отвечает «оплачено» на любой вопрос: исход тестового платежа задаёт
        // test-psp, а не опрос.
        $testConnectors = array_keys((array) config('payswitch.connectors'), TestConnector::class, true);

        $this->each(
            $this->payments->dueForProviderPoll(self::PAYMENT_STATUSES, $testConnectors, count(self::SCHEDULE), $firstBefore, $limit),
            $deadline,
            fn (PaymentIntent $payment) => $this->pollPayment($payment),
        );
        $this->each(
            $this->refunds->dueForProviderPoll(count(self::SCHEDULE), $firstBefore, $limit),
            $deadline,
            fn (Refund $refund) => $this->pollRefund($refund),
        );
    }

    /**
     * Один платёж или возврат опрашивается одним процессом; упавший не держит остальных.
     *
     * @template TModel of Model
     *
     * @param  iterable<TModel>  $due
     * @param  \Closure(TModel): void  $poll
     */
    private function each(iterable $due, float $deadline, \Closure $poll): void
    {
        foreach ($due as $model) {
            if (microtime(true) >= $deadline) {
                return;
            }

            $lock = Cache::lock("provider-poll:{$model->getTable()}:{$model->getKey()}", 120);
            if (! $lock->get()) {
                continue;
            }

            try {
                $poll($model);
            } catch (\Throwable $e) {
                report($e);
            } finally {
                $lock->release();
            }
        }
    }

    private function pollPayment(PaymentIntent $payment): void
    {
        try {
            $this->paymentService->sync($payment->key, $payment->merchant_account_id);
        } catch (\Throwable $e) {
            // Коннектор отключён или провайдер не ответил — тоже опрос без итога.
            Log::warning('Provider poll: payment sync failed', ['payment_id' => $payment->key, 'error' => $e->getMessage()]);
        }

        $payment->refresh();
        if (! in_array($payment->status, self::PAYMENT_STATUSES, true)) {
            return;
        }

        $state = self::after($payment->poll_attempts + 1);
        if ($state['next_poll_at'] !== null) {
            $this->payments->update($payment, $state);

            return;
        }

        $this->giveUp($payment->id);
    }

    /**
     * Сутки без итога. processing — провайдер мог взять деньги, решает человек
     * (requires_merchant_action); requires_customer_action без срока — expired.
     * Счётчик не пишем: не удалось — следующий прогон повторит.
     */
    private function giveUp(int $paymentId): void
    {
        $previous = null;
        $payment = DB::transaction(function () use ($paymentId, &$previous) {
            $payment = $this->payments->findByIdLocked($paymentId);
            if (! $payment || ! in_array($payment->status, self::PAYMENT_STATUSES, true)) {
                return null;
            }

            $previous = $payment->status;

            return $this->payments->update($payment, [
                'status' => $previous === PaymentStatus::Processing ? PaymentStatus::RequiresMerchantAction : PaymentStatus::Expired,
                'error_code' => self::UNCONFIRMED,
                'error_message' => 'The provider did not confirm the outcome within a day of polling',
            ]);
        });

        if ($payment === null || $previous === null) {
            return;
        }

        Log::error('Provider poll: no outcome within a day, payment closed', ['payment_id' => $payment->key, 'from' => $previous->value, 'to' => $payment->status->value]);

        // Недосланное событие догонит ReconcileWebhookEventsJob.
        try {
            event(new PaymentStatusChanged($payment, $previous->value));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function pollRefund(Refund $refund): void
    {
        if ($this->refundReconciliation->reconcile($refund)) {
            return;
        }

        $state = self::after($refund->poll_attempts + 1);
        $this->refunds->updateRefund($refund, $state);

        // Конечного статуса нет намеренно: деньги могли уйти, а события «возврат на
        // разборе» у мерчанта нет. pending держит сумму; закроет уведомление или человек.
        if ($state['next_poll_at'] === null) {
            Log::error('Provider poll: refund still pending after a day, left for manual handling', ['refund_id' => $refund->key, 'connector' => $refund->connector]);
        }
    }

    /**
     * @return array{poll_attempts: int, next_poll_at: Carbon|null}
     */
    private static function after(int $attempts): array
    {
        return [
            'poll_attempts' => $attempts,
            'next_poll_at' => isset(self::SCHEDULE[$attempts]) ? now()->addMinutes(self::SCHEDULE[$attempts]) : null,
        ];
    }
}
