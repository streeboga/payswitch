<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PaymentStatusChanged;
use App\Jobs\DeliverWebhookJob;
use App\Repositories\Contracts\WebhookEventRepositoryInterface;
use Streeboga\PaymentData\Enums\CaptureMethod;

class SendWebhookNotification
{
    public function __construct(
        private WebhookEventRepositoryInterface $webhookRepository,
    ) {}

    public function handle(PaymentStatusChanged $event): void
    {
        $payment = $event->payment;

        $eventType = match ($payment->status->value) {
            'succeeded' => $payment->capture_method === CaptureMethod::Manual
                ? 'payment_captured'
                : 'payment_succeeded',
            'cancelled' => 'payment_cancelled',
            'requires_capture' => 'payment_authorized',
            default => 'payment_status_changed',
        };

        $webhookEvent = $this->webhookRepository->create([
            'event_type' => $eventType,
            'merchant_account_id' => $payment->merchant_account_id,
            'payment_intent_id' => $payment->id,
            'content' => [
                'payment_id' => $payment->key,
                'status' => $payment->status->value,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
            ],
            'delivered' => false,
            'delivery_attempts' => 0,
        ]);

        DeliverWebhookJob::dispatch($webhookEvent->id);
    }
}
