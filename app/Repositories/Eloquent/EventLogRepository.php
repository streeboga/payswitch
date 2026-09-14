<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\EventLogRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Streeboga\PaymentData\Models\PaymentIntent;

final readonly class EventLogRepository implements EventLogRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function paginateForMerchant(int|string $merchantId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $webhooks = DB::table('webhook_events')
            ->join('payment_intents', 'webhook_events.payment_intent_id', '=', 'payment_intents.id')
            ->where('webhook_events.merchant_account_id', $merchantId)
            ->select([
                'webhook_events.key as event_id',
                DB::raw("'webhook' as type"),
                'webhook_events.event_type as action',
                'payment_intents.key as resource_id',
                DB::raw("CASE WHEN webhook_events.delivered = true THEN 'delivered' ELSE 'failed' END as status"),
                'webhook_events.last_error as detail',
                'webhook_events.created_at',
            ]);

        // Смены статуса пишет LogPaymentAudit в activity_log (spatie);
        // payment_audit_log с 18.03 не пополняется. Статусы — в json
        // properties: построитель даёт ->> в Postgres и json_extract в sqlite.

        $audits = DB::table('activity_log')
            ->join('payment_intents', function (JoinClause $join) {
                $join->on('activity_log.subject_id', '=', 'payment_intents.id')
                    ->where('activity_log.subject_type', (new PaymentIntent)->getMorphClass());
            })
            ->where('activity_log.log_name', 'payment')
            ->where('activity_log.event', 'status_changed')
            ->where('payment_intents.merchant_account_id', $merchantId)
            ->select([
                DB::raw('CAST(activity_log.id AS TEXT) as event_id'),
                DB::raw("'status_change' as type"),
                'activity_log.event as action',
                'payment_intents.key as resource_id',
                'activity_log.properties->new_status as status',
                // Прежний статус; «prev → new» собирается после выборки.
                'activity_log.properties->previous_status as detail',
                'activity_log.created_at',
            ]);

        if (! empty($filters['type'])) {
            if ($filters['type'] === 'webhook') {
                $audits->whereRaw('1 = 0');
            } elseif ($filters['type'] === 'status_change') {
                $webhooks->whereRaw('1 = 0');
            }
        }

        if (! empty($filters['from']) && ! empty($filters['to'])) {
            $webhooks->whereBetween('webhook_events.created_at', [$filters['from'], $filters['to']]);
            $audits->whereBetween('activity_log.created_at', [$filters['from'], $filters['to']]);
        }

        $union = $webhooks->unionAll($audits);

        $paginator = DB::query()
            ->fromSub($union, 'events')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        foreach ($paginator->items() as $row) {
            if ($row instanceof \stdClass && $row->type === 'status_change') {
                $row->detail = "{$row->detail} → {$row->status}";
            }
        }

        return $paginator;
    }
}
