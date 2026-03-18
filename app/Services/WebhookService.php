<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\WebhookEventType;
use App\Jobs\DeliverWebhookJob;
use App\Repositories\Contracts\WebhookEventRepositoryInterface;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\PaymentIntent;

final readonly class WebhookService
{
    public function __construct(
        private WebhookEventRepositoryInterface $webhookRepository,
    ) {}

    public function dispatchForPayment(PaymentIntent $payment): void
    {
        $eventType = match ($payment->status) {
            PaymentStatus::Succeeded => $payment->capture_method === CaptureMethod::Manual
                ? WebhookEventType::PaymentCaptured->value
                : WebhookEventType::PaymentSucceeded->value,
            PaymentStatus::Cancelled => WebhookEventType::PaymentCancelled->value,
            PaymentStatus::RequiresCapture => WebhookEventType::PaymentAuthorized->value,
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
        ]);

        DeliverWebhookJob::dispatch($webhookEvent->id);
    }
}
