<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Repositories\Contracts\RefundRepositoryInterface;
use App\Services\RefundReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Streeboga\PaymentData\Models\Refund;

/**
 * Сверка: возврат остался pending (502 refund_pending), а уведомление провайдера об
 * итоге не пришло или не разобрано. Спрашиваем провайдера сами.
 *
 * В расписании только при PAYSWITCH_REFUND_RECONCILE_ENABLED=true.
 *
 * ponytail: каждый прогон спрашивает про все pending-возвраты окна, без затухания —
 * их единицы; счётчик попыток и backoff, если провайдер начнёт резать по частоте.
 */
final class ReconcilePendingRefundsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Как у ReconcileWebhookEventsJob: уникальность до конца работы, а не до постановки. */
    public int $uniqueFor = 600;

    /** Не трогать свежие: ответ на сам запрос возврата ещё может писаться. */
    private const int GRACE_MINUTES = 2;

    /** Дальше — разбирать руками. */
    private const int LOOKBACK_DAYS = 14;

    public function handle(RefundRepositoryInterface $refunds, RefundReconciliationService $reconciliation): void
    {
        $refunds->pendingWithProviderReference(now()->subDays(self::LOOKBACK_DAYS), now()->subMinutes(self::GRACE_MINUTES))
            ->chunkById(100, function ($batch) use ($reconciliation) {
                /** @var Refund $refund */
                foreach ($batch as $refund) {
                    // Один упавший возврат не держит остальные.
                    try {
                        $reconciliation->reconcile($refund);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });
    }
}
