<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\EventLogRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final readonly class EventLogRepository implements EventLogRepositoryInterface
{
    public function paginateForMerchant(int|string $merchantId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $webhooks = DB::table('webhook_events')
            ->where('merchant_account_id', $merchantId)
            ->select([
                'key as event_id',
                DB::raw("'webhook' as type"),
                'event_type as action',
                'payment_intent_id as resource_id',
                DB::raw("CASE WHEN delivered = true THEN 'delivered' ELSE 'failed' END as status"),
                'last_error as detail',
                'created_at',
            ]);

        $audits = DB::table('payment_audit_log')
            ->where('merchant_account_id', $merchantId)
            ->select([
                DB::raw('CAST(id AS TEXT) as event_id'),
                DB::raw("'status_change' as type"),
                'action',
                'payment_intent_id as resource_id',
                'new_status as status',
                DB::raw("(previous_status || ' → ' || new_status) as detail"),
                'created_at',
            ]);

        if (! empty($filters['type'])) {
            if ($filters['type'] === 'webhook') {
                $audits->whereRaw('1 = 0');
            } elseif ($filters['type'] === 'status_change') {
                $webhooks->whereRaw('1 = 0');
            }
        }

        if (! empty($filters['from']) && ! empty($filters['to'])) {
            $webhooks->whereBetween('created_at', [$filters['from'], $filters['to']]);
            $audits->whereBetween('created_at', [$filters['from'], $filters['to']]);
        }

        $union = $webhooks->unionAll($audits);

        return DB::query()
            ->fromSub($union, 'events')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
