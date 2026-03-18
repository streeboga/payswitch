<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Streeboga\PaymentData\Models\WebhookEvent;

interface WebhookEventRepositoryInterface
{
    public function create(array $attributes): WebhookEvent;

    public function findById(int $id): ?WebhookEvent;

    public function findByKeyForMerchant(string $key, int|string $merchantId): WebhookEvent;

    public function paginateForMerchant(int|string $merchantId, array $filters = [], int $perPage = 20): LengthAwarePaginator;

    public function markDelivered(WebhookEvent $event, int $attempts): void;

    public function markFailed(WebhookEvent $event, int $attempts, string $error): void;
}
