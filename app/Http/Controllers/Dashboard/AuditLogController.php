<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Dashboard Audit Log', description: 'Audit trail of all system actions', weight: 21)]
final class AuditLogController extends Controller
{
    /**
     * List audit log entries
     *
     * Paginated list of audit log entries with optional filters.
     */
    #[QueryParameter('filter[causer_id]', type: 'integer', description: 'Filter by user ID')]
    #[QueryParameter('filter[event]', type: 'string', description: 'Filter by event type', enum: ['created', 'updated', 'deleted'])]
    #[QueryParameter('filter[subject_type]', type: 'string', description: 'Filter by resource type')]
    #[QueryParameter('filter[from]', type: 'string', description: 'Start date (YYYY-MM-DD)')]
    #[QueryParameter('filter[to]', type: 'string', description: 'End date (YYYY-MM-DD)')]
    #[QueryParameter('page[size]', type: 'integer', description: 'Items per page', example: 20)]
    #[Response(200, description: 'Paginated audit log')]
    public function index(Request $request): JsonResponse
    {
        $query = Activity::query()->orderByDesc('created_at');

        if ($causerId = $request->input('filter.causer_id')) {
            $query->where('causer_id', $causerId);
        }
        if ($event = $request->input('filter.event')) {
            $query->where('event', $event);
        }
        if ($subjectType = $request->input('filter.subject_type')) {
            $query->where('subject_type', $subjectType);
        }
        if ($from = $request->input('filter.from')) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->input('filter.to')) {
            $query->where('created_at', '<=', $to.' 23:59:59');
        }

        $perPage = min((int) $request->input('page.size', 20), 100);
        $paginator = $query->paginate($perPage);

        $items = collect($paginator->items())->map(fn (Activity $activity) => [
            'type' => 'audit-logs',
            'id' => (string) $activity->id,
            'attributes' => [
                'log_name' => $activity->log_name,
                'description' => $activity->description,
                'event' => $activity->event,
                'subject_type' => $activity->subject_type,
                'subject_id' => $activity->subject_id,
                'causer_type' => $activity->causer_type,
                'causer_id' => $activity->causer_id,
                'properties' => $activity->properties?->toArray(),
                'created_at' => $activity->created_at->toIso8601String(),
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

    /**
     * Export audit log
     *
     * Stream a CSV export of audit log entries.
     */
    #[Response(200, description: 'CSV file stream')]
    public function export(Request $request): StreamedResponse
    {
        $query = Activity::query()->orderByDesc('created_at');

        if ($from = $request->input('filter.from')) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->input('filter.to')) {
            $query->where('created_at', '<=', $to.' 23:59:59');
        }

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'event', 'description', 'subject_type', 'subject_id', 'causer_id', 'created_at']);

            foreach ($query->cursor() as $activity) {
                fputcsv($out, [
                    $activity->id,
                    $activity->event,
                    $activity->description,
                    $activity->subject_type,
                    $activity->subject_id,
                    $activity->causer_id,
                    $activity->created_at->toIso8601String(),
                ]);
            }

            fclose($out);
        }, 'audit-log-export.csv', ['Content-Type' => 'text/csv']);
    }
}
