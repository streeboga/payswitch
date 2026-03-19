<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventLogResource;
use App\Services\EventLogService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;

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
    #[QueryParameter('filter[type]', type: 'string', description: 'Event type filter (webhook, status_change)')]
    #[QueryParameter('filter[from]', type: 'string', description: 'Start date (YYYY-MM-DD)')]
    #[QueryParameter('filter[to]', type: 'string', description: 'End date (YYYY-MM-DD)')]
    #[QueryParameter('page[size]', type: 'integer', description: 'Items per page', example: 20)]
    #[Response(200, description: 'Paginated event log')]
    public function index(Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        Gate::authorize('event-log.viewAny', [$merchantId]);

        // Resolve page number from JSON:API page[number] param
        $pageNumber = (int) $request->input('page.number', 1);
        Paginator::currentPageResolver(fn () => $pageNumber);

        $paginator = $this->eventLogService->list($merchantId, [
            'type' => $request->input('filter.type'),
            'from' => $request->input('filter.from'),
            'to' => $request->input('filter.to'),
        ], (int) $request->input('page.size', 20));

        return EventLogResource::jsonApiCollection($paginator, $request);
    }
}
