<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\AppNotification;
use App\Repositories\Contracts\NotificationRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class NotificationRepository implements NotificationRepositoryInterface
{
    public function paginateForUser(int $userId, ?string $type, ?string $read, int $perPage): LengthAwarePaginator
    {
        $query = AppNotification::where('user_id', $userId);

        if ($type !== null) {
            $query->where('type', $type);
        }

        if ($read === 'true') {
            $query->whereNotNull('read_at');
        } elseif ($read === 'false') {
            $query->whereNull('read_at');
        }

        return $query->orderByDesc('created_at')->paginate(min($perPage, 100));
    }

    public function findByKeyOrFail(int $userId, string $notificationKey): AppNotification
    {
        return AppNotification::where('user_id', $userId)
            ->where('key', $notificationKey)
            ->firstOrFail();
    }

    public function markRead(AppNotification $notification): void
    {
        $notification->update(['read_at' => now()]);
    }

    public function markAllReadForUser(int $userId): void
    {
        AppNotification::where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function delete(AppNotification $notification): void
    {
        $notification->delete();
    }

    public function unreadCountForUser(int $userId): int
    {
        return AppNotification::where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }
}
