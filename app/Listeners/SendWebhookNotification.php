<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\WebhookEventType;
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
                ? WebhookEventType::PaymentCaptured->value
                : WebhookEventType::PaymentSucceeded->value,
            'cancelled' => WebhookEventType::PaymentCancelled->value,
            'requires_capture' => WebhookEventType::PaymentAuthorized->value,
            default => WebhookEventType::PaymentStatusChanged->value,
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
