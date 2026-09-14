<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Services\AuditLogService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Dashboard Audit Log', description: 'Audit trail of all system actions', weight: 21)]
final class AuditLogController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * List audit log entries
     *
     * Paginated list of audit log entries with optional filters.
     */
    #[QueryParameter('filter[causer_id]', type: 'integer', description: 'Filter by user ID')]
    #[QueryParameter('filter[event]', type: 'string', description: 'Filter by event type (created, updated, deleted)')]
    #[QueryParameter('filter[subject_type]', type: 'string', description: 'Filter by resource type')]
    #[QueryParameter('filter[from]', type: 'string', description: 'Start date (YYYY-MM-DD)')]
    #[QueryParameter('filter[to]', type: 'string', description: 'End date (YYYY-MM-DD)')]
    #[QueryParameter('page[size]', type: 'integer', description: 'Items per page', example: 20)]
    #[Response(200, description: 'Paginated audit log')]
    public function index(Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        Gate::authorize('audit-log.viewAny', [$merchantId]);

        $filters = [
            'causer_id' => $request->input('filter.causer_id'),
            'event' => $request->input('filter.event'),
            'subject_type' => $request->input('filter.subject_type'),
            'from' => $request->input('filter.from'),
            'to' => $request->input('filter.to'),
        ];

        $perPage = min((int) $request->input('page.size', 20), 100);
        $paginator = $this->auditLogService->getPaginated($merchantId, $filters, $perPage);

        return AuditLogResource::jsonApiCollection($paginator, $request);
    }

    /**
     * Export audit log
     *
     * Stream a CSV export of audit log entries.
     */
    #[Response(200, description: 'CSV file stream')]
    public function export(Request $request): StreamedResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        Gate::authorize('audit-log.export', [$merchantId]);

        $filters = [
            'from' => $request->input('filter.from'),
            'to' => $request->input('filter.to'),
        ];

        $cursor = $this->auditLogService->getCursorForExport($merchantId, $filters);

        return response()->streamDownload(function () use ($cursor) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                throw new \RuntimeException('Failed to open php://output for CSV export');
            }
            fputcsv($out, ['id', 'event', 'description', 'subject_type', 'subject_id', 'causer_id', 'created_at']);

            foreach ($cursor as $activity) {
                fputcsv($out, [
                    $activity->id,
                    $activity->event,
                    $activity->description,
                    $activity->subject_type,
                    $activity->subject_id,
                    $activity->causer_id,
                    $activity->created_at?->toIso8601String() ?? '',
                ]);
            }

            fclose($out);
        }, 'audit-log-export.csv', ['Content-Type' => 'text/csv']);
    }
}
