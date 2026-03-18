<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppNotification;
use App\Repositories\Contracts\NotificationRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class NotificationService
{
    public function __construct(
        private NotificationRepositoryInterface $notifications,
    ) {}

    /**
     * List notifications for a user with optional filters.
     *
     * @return LengthAwarePaginator<int, AppNotification>
     */
    public function list(int $userId, ?string $type, ?string $read, int $perPage): LengthAwarePaginator
    {
        return $this->notifications->paginateForUser($userId, $type, $read, $perPage);
    }

    /**
     * Find a notification by key for a given user, or fail.
     */
    public function findByKeyOrFail(int $userId, string $notificationKey): AppNotification
    {
        return $this->notifications->findByKeyOrFail($userId, $notificationKey);
    }

    /**
     * Mark a single notification as read.
     */
    public function markRead(int $userId, string $notificationKey): AppNotification
    {
        $notification = $this->notifications->findByKeyOrFail($userId, $notificationKey);
        $this->notifications->markRead($notification);

        return $notification;
    }

    /**
     * Mark all unread notifications as read for a user.
     */
    public function markAllRead(int $userId): void
    {
        $this->notifications->markAllReadForUser($userId);
    }

    /**
     * Delete a notification by key for a given user.
     */
    public function delete(int $userId, string $notificationKey): void
    {
        $notification = $this->notifications->findByKeyOrFail($userId, $notificationKey);
        $this->notifications->delete($notification);
    }

    /**
     * Get the count of unread notifications for a user.
     */
    public function unreadCount(int $userId): int
    {
        return $this->notifications->unreadCountForUser($userId);
    }
}
