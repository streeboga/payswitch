<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Repositories\Contracts\PaymentIntentRepositoryInterface;
use App\Services\WebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\PaymentIntent;

/**
 * Сверка: статус платежа зафиксирован, а уведомления о нём нет.
 *
 * WebhookEvent создаёт слушатель PaymentStatusChanged уже после коммита
 * статуса. Упал слушатель или процесс между коммитом и событием — статус
 * `succeeded`, события нет, а повтор от PSP не пройдёт (succeeded → succeeded
 * запрещён). Получатель денег так и не узнает о зачислении. Джоба догоняет
 * такие платежи штатным dispatchForPayment.
 *
 * ponytail: сверка по расписанию, а не outbox в транзакции статуса — outbox,
 * если окно в 5 минут станет недопустимым.
 */
final class ReconcileWebhookEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Статусы, о которых мерчанту уходит уведомление. */
    private const array STATUSES = [
        PaymentStatus::Succeeded,
        PaymentStatus::RequiresCapture,
        PaymentStatus::Cancelled,
        PaymentStatus::Failed,
        PaymentStatus::Expired,
        PaymentStatus::RequiresMerchantAction,
        PaymentStatus::PartiallyCaptured,
        PaymentStatus::PartiallyCapturedAndCapturable,
    ];

    /** Не трогать свежие: слушатель ещё может работать. */
    private const int GRACE_MINUTES = 2;

    /** Дальше — разбирать руками, а не слать мерчанту архив. */
    private const int LOOKBACK_DAYS = 7;

    public function handle(PaymentIntentRepositoryInterface $payments, WebhookService $webhookService): void
    {
        $payments->findWithoutWebhookForCurrentStatus(
            self::STATUSES,
            now()->subDays(self::LOOKBACK_DAYS),
            now()->subMinutes(self::GRACE_MINUTES),
        )->chunkById(100, function ($batch) use ($webhookService) {
            /** @var PaymentIntent $payment */
            foreach ($batch as $payment) {
                Log::warning("Webhook event missing for payment {$payment->key} in status {$payment->status->value}, dispatching", [
                    'payment_id' => $payment->key,
                    'merchant_account_id' => $payment->merchant_account_id,
                    'status' => $payment->status->value,
                ]);

                $webhookService->dispatchForPayment($payment);
            }
        });
    }
}
