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
     * @return LengthAwarePaginator<int, Activity>
     */
    public function getPaginated(int|string $merchantId, array $filters, int $perPage): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $filters
     * @return LazyCollection<int, Activity>
     */
    public function getCursorForExport(int|string $merchantId, array $filters): LazyCollection;
}
