<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;
use Spatie\Activitylog\Models\Activity;

interface AuditLogRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function getPaginated(array $filters, int $perPage): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $filters
     * @return LazyCollection<int, Activity>
     */
    public function getCursorForExport(array $filters): LazyCollection;
}
