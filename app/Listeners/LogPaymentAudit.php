<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PaymentStatusChanged;

final class LogPaymentAudit
{
    public function handle(PaymentStatusChanged $event): void
    {
        $payment = $event->payment;

        activity('payment')
            ->performedOn($payment)
            ->withProperties([
                'previous_status' => $event->previousStatus ?? $payment->status->value,
                'new_status' => $payment->status->value,
                'merchant_account_id' => $payment->merchant_account_id,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
            ])
            ->event('status_changed')
            ->log('status_changed');
    }
}
