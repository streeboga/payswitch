<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\AppNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface NotificationRepositoryInterface
{
    public function paginateForUser(int $userId, ?string $type, ?string $read, int $perPage): LengthAwarePaginator;

    public function findByKeyOrFail(int $userId, string $notificationKey): AppNotification;

    public function markRead(AppNotification $notification): void;

    public function markAllReadForUser(int $userId): void;

    public function delete(AppNotification $notification): void;

    public function unreadCountForUser(int $userId): int;
}
