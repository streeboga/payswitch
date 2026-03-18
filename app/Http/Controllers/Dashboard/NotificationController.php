<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppNotificationResource;
use App\Models\AppNotification;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Dashboard Notifications', description: 'User notification management', weight: 22)]
final class NotificationController extends Controller
{
    /**
     * List notifications
     *
     * Retrieve notifications for the authenticated user.
     */
    #[QueryParameter('filter[type]', type: 'string', description: 'Filter by notification type')]
    #[QueryParameter('filter[read]', type: 'string', description: 'Filter by read status', enum: ['true', 'false'])]
    #[QueryParameter('page[size]', type: 'integer', description: 'Items per page', example: 20)]
    #[Response(200, description: 'Paginated notification list')]
    public function index(Request $request): JsonResponse
    {
        $query = AppNotification::where('user_id', $request->user()->id);

        if ($type = $request->input('filter.type')) {
            $query->where('type', $type);
        }

        $read = $request->input('filter.read');
        if ($read === 'true') {
            $query->whereNotNull('read_at');
        } elseif ($read === 'false') {
            $query->whereNull('read_at');
        }

        $perPage = min((int) $request->input('page.size', 20), 100);
        $paginator = $query->orderByDesc('created_at')->paginate($perPage);

        return AppNotificationResource::jsonApiCollection($paginator, $request);
    }

    /**
     * Mark notification as read
     *
     * Mark a single notification as read.
     */
    #[PathParameter('notificationKey', description: 'Notification public key')]
    #[Response(200, description: 'Notification marked as read')]
    #[Response(404, description: 'Notification not found')]
    public function markRead(string $notificationKey, Request $request): JsonResponse
    {
        $notification = AppNotification::where('user_id', $request->user()->id)
            ->where('key', $notificationKey)
            ->firstOrFail();

        $notification->update(['read_at' => now()]);

        return (new AppNotificationResource($notification))->toResponse($request);
    }

    /**
     * Mark all notifications as read
     *
     * Mark all unread notifications as read for the authenticated user.
     */
    #[Response(200, description: 'All notifications marked as read')]
    public function markAllRead(Request $request): JsonResponse
    {
        AppNotification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'data' => ['type' => 'notification-actions', 'id' => '1', 'attributes' => ['status' => 'done']],
        ], 200, ['Content-Type' => 'application/vnd.api+json']);
    }

    /**
     * Delete notification
     *
     * Soft-delete a notification.
     */
    #[PathParameter('notificationKey', description: 'Notification public key')]
    #[Response(204, description: 'Notification deleted')]
    public function destroy(string $notificationKey, Request $request): JsonResponse
    {
        $notification = AppNotification::where('user_id', $request->user()->id)
            ->where('key', $notificationKey)
            ->firstOrFail();

        $notification->delete();

        return response()->json(null, 204);
    }

    /**
     * Get unread notification count
     *
     * Returns the count of unread notifications for the authenticated user.
     */
    #[Response(200, description: 'Unread count')]
    public function unreadCount(Request $request): JsonResponse
    {
        $count = AppNotification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['count' => $count]);
    }
}
