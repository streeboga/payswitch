<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\StateMachine\PaymentStateMachine;

final class CleanExpiredPaymentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        $expirableStatuses = [
            PaymentStatus::RequiresPaymentMethod,
            PaymentStatus::RequiresConfirmation,
            PaymentStatus::RequiresCustomerAction,
        ];

        $expired = PaymentIntent::whereNotNull('expires_on')
            ->where('expires_on', '<', now())
            ->whereIn('status', $expirableStatuses)
            ->get();

        $count = 0;
        foreach ($expired as $payment) {
            if (PaymentStateMachine::canTransition($payment->status, PaymentStatus::Expired)) {
                $payment->update(['status' => PaymentStatus::Expired]);
                $count++;
            }
        }

        if ($count > 0) {
            Log::info("Expired {$count} payments");
        }
    }
}
