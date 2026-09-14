<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\User;
use App\Models\UserRole;
use App\Repositories\Contracts\AuditLogRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;
use Spatie\Activitylog\Models\Activity;
use Streeboga\PaymentData\Models\MerchantAccount;

final readonly class AuditLogRepository implements AuditLogRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Activity>
     */
    public function getPaginated(int|string $merchantId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->buildFilteredQuery($merchantId, $filters)->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LazyCollection<int, Activity>
     */
    public function getCursorForExport(int|string $merchantId, array $filters): LazyCollection
    {
        return $this->buildFilteredQuery($merchantId, $filters)->cursor();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Activity>
     */
    private function buildFilteredQuery(int|string $merchantId, array $filters): Builder
    {
        // Своей колонки мерчанта у журнала нет. Видны только действия
        // пользователей организации мерчанта; записи без автора не видны никому.
        // ponytail: пользователь нескольких организаций виден во всех сразу;
        // точнее — класть merchant_id в properties при записи и фильтровать по нему.
        $query = Activity::query()
            ->where('causer_type', (new User)->getMorphClass())
            ->whereIn('causer_id', UserRole::query()
                ->select('user_id')
                ->whereIn('organization_id', MerchantAccount::query()->select('org_id')->where('id', $merchantId)))
            ->orderByDesc('created_at');

        if (! empty($filters['causer_id'])) {
            $query->where('causer_id', $filters['causer_id']);
        }
        if (! empty($filters['event'])) {
            $query->where('event', $filters['event']);
        }
        if (! empty($filters['subject_type'])) {
            $query->where('subject_type', $filters['subject_type']);
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to'].' 23:59:59');
        }

        return $query;
    }
}
