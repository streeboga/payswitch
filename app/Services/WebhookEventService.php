<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeliverWebhookJob;
use App\Repositories\Contracts\WebhookEventRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Streeboga\PaymentData\Models\WebhookEvent;

final readonly class WebhookEventService
{
    public function __construct(
        private WebhookEventRepositoryInterface $webhookEventRepository,
    ) {}

    public function paginateForMerchant(int|string $merchantId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->webhookEventRepository->paginateForMerchant($merchantId, $filters, $perPage);
    }

    public function findByKeyForMerchant(string $eventKey, int|string $merchantId): WebhookEvent
    {
        return $this->webhookEventRepository->findByKeyForMerchant($eventKey, $merchantId);
    }

    public function retry(string $eventKey, int|string $merchantId): WebhookEvent
    {
        $event = $this->webhookEventRepository->findByKeyForMerchant($eventKey, $merchantId);
        DeliverWebhookJob::dispatch($event->id);

        return $event;
    }
}
