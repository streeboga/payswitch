<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Repositories\Contracts\PaymentIntentRepositoryInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\StateMachine\PaymentStateMachine;

final class CleanExpiredPaymentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 60];

    public function handle(PaymentIntentRepositoryInterface $paymentRepository): void
    {
        $expirableStatuses = [
            PaymentStatus::RequiresPaymentMethod,
            PaymentStatus::RequiresConfirmation,
            PaymentStatus::RequiresCustomerAction,
        ];

        $count = 0;
        $paymentRepository->findExpiredInStatuses($expirableStatuses)->chunkById(100, function ($payments) use ($paymentRepository, &$count) {
            foreach ($payments as $payment) {
                if (PaymentStateMachine::canTransition($payment->status, PaymentStatus::Expired)) {
                    $paymentRepository->update($payment, ['status' => PaymentStatus::Expired]);
                    $count++;
                }
            }
        });

        if ($count > 0) {
            Log::info("Expired {$count} payments");
        }
    }
}
