<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\PaymentStatusChanged;
use App\Repositories\Contracts\PaymentIntentRepositoryInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\StateMachine\PaymentStateMachine;

final class CleanExpiredPaymentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 60];

    private const array EXPIRABLE_STATUSES = [
        PaymentStatus::RequiresPaymentMethod,
        PaymentStatus::RequiresConfirmation,
        PaymentStatus::RequiresCustomerAction,
    ];

    public function handle(PaymentIntentRepositoryInterface $paymentRepository): void
    {
        $count = 0;
        $paymentRepository->findExpiredInStatuses(self::EXPIRABLE_STATUSES)->chunkById(100, function ($payments) use ($paymentRepository, &$count) {
            /** @var PaymentIntent $candidate */
            foreach ($payments as $candidate) {
                if ($this->expire($paymentRepository, $candidate->id)) {
                    $count++;
                }
            }
        });

        if ($count > 0) {
            Log::info("Expired {$count} payments");
        }
    }

    /**
     * Статус перечитывается под блокировкой: между выборкой и записью Pay от
     * PSP мог закоммитить `succeeded`, и затирать его на `expired` нельзя.
     */
    private function expire(PaymentIntentRepositoryInterface $paymentRepository, int $paymentId): bool
    {
        $previousStatus = null;
        $payment = DB::transaction(function () use ($paymentRepository, $paymentId, &$previousStatus) {
            $payment = $paymentRepository->findByIdLocked($paymentId);

            if (! $payment
                || ! in_array($payment->status, self::EXPIRABLE_STATUSES, true)
                || ! PaymentStateMachine::canTransition($payment->status, PaymentStatus::Expired)) {
                return $payment;
            }

            $previousStatus = $payment->status->value;

            return $paymentRepository->update($payment, ['status' => PaymentStatus::Expired]);
        });

        if ($previousStatus === null || ! $payment) {
            return false;
        }

        // Мерчант должен узнать об истечении. Упавший слушатель не держит
        // остальную пачку — недосланное догонит ReconcileWebhookEventsJob.
        try {
            event(new PaymentStatusChanged($payment, $previousStatus));
        } catch (\Throwable $e) {
            report($e);
        }

        return true;
    }
}
