<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\EventLogService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Dashboard Event Logs', description: 'Combined webhook and payment status change timeline', weight: 18)]
final class EventLogController extends Controller
{
    public function __construct(
        private readonly EventLogService $eventLogService,
    ) {}

    /**
     * List event logs
     *
     * Combined timeline of webhook deliveries and payment status changes.
     */
    #[QueryParameter('filter[type]', type: 'string', description: 'Event type filter', enum: ['webhook', 'status_change'])]
    #[QueryParameter('filter[from]', type: 'string', description: 'Start date (YYYY-MM-DD)')]
    #[QueryParameter('filter[to]', type: 'string', description: 'End date (YYYY-MM-DD)')]
    #[QueryParameter('page[size]', type: 'integer', description: 'Items per page', example: 20)]
    #[Response(200, description: 'Paginated event log')]
    public function index(Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');

        $paginator = $this->eventLogService->list($merchantId, [
            'type' => $request->input('filter.type'),
            'from' => $request->input('filter.from'),
            'to' => $request->input('filter.to'),
        ], (int) $request->input('page.size', 20));

        $items = collect($paginator->items())->map(fn ($row, $index) => [
            'type' => 'event-logs',
            'id' => $row->event_id,
            'attributes' => [
                'event_type' => $row->type,
                'action' => $row->action,
                'resource_id' => $row->resource_id,
                'status' => $row->status,
                'detail' => $row->detail,
                'created_at' => $row->created_at,
            ],
        ])->toArray();

        return response()->json([
            'data' => $items,
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 200, ['Content-Type' => 'application/vnd.api+json']);
    }
}
