<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Contracts\AuditLogRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;
use Spatie\Activitylog\Models\Activity;

final readonly class AuditLogService
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogs,
    ) {}

    /**
     * Get paginated audit log entries with optional filters.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Activity>
     */
    public function getPaginated(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->auditLogs->getPaginated($filters, $perPage);
    }

    /**
     * Get a lazy cursor of audit log entries for export.
     *
     * @param  array<string, mixed>  $filters
     * @return LazyCollection<int, Activity>
     */
    public function getCursorForExport(array $filters): LazyCollection
    {
        return $this->auditLogs->getCursorForExport($filters);
    }
}
