<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PaymentStatusChanged;
use App\Services\WebhookService;

final readonly class SendWebhookNotification
{
    public function __construct(
        private WebhookService $webhookService,
    ) {}

    public function handle(PaymentStatusChanged $event): void
    {
        $this->webhookService->dispatchForPayment($event->payment);
    }
}
