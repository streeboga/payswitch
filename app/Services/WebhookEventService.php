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

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, WebhookEvent>
     */
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

    /**
     * Снова ставит в очередь уже записанные недоставленные события: задание доставки
     * потеряно (очередь чистили, воркер убит). Новых событий и денег не создаёт —
     * event_id и тело те же, приёмник отличает повтор по event_id.
     *
     * Событие, чьё задание ещё живо и ждёт своего повтора, получит второе задание и
     * лишнюю попытку — поэтому только вручную и только нетронутые дольше $olderThanMinutes.
     */
    public function retryUndelivered(int $limit, int $olderThanMinutes): int
    {
        $ids = $this->webhookEventRepository->undeliveredIds(now()->subMinutes(max(0, $olderThanMinutes)), max(1, min($limit, 1000)));

        foreach ($ids as $id) {
            DeliverWebhookJob::dispatch($id);
        }

        return count($ids);
    }
}
