<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PaymentStatusChanged;
use Streeboga\PaymentData\Models\PaymentAuditLog;

class LogPaymentAudit
{
    public function handle(PaymentStatusChanged $event): void
    {
        $payment = $event->payment;

        PaymentAuditLog::create([
            'payment_intent_id' => $payment->id,
            'merchant_account_id' => $payment->merchant_account_id,
            'action' => 'status_changed',
            'previous_status' => $payment->getOriginal('status')?->value ?? $payment->status->value,
            'new_status' => $payment->status->value,
            'created_at' => now(),
        ]);
    }
}
