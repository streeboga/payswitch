<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\ConnectorHealthRepositoryInterface;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final readonly class ConnectorHealthRepository implements ConnectorHealthRepositoryInterface
{
    public function getHealthStats(int|string $merchantId, string $connectorName, CarbonInterface $since): array
    {
        $stats = DB::table('payment_attempts')
            ->join('payment_intents', 'payment_attempts.payment_intent_id', '=', 'payment_intents.id')
            ->where('payment_intents.merchant_account_id', $merchantId)
            ->where('payment_attempts.connector', $connectorName)
            ->where('payment_attempts.created_at', '>=', $since)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN payment_attempts.status = 'succeeded' THEN 1 ELSE 0 END) as success_count")
            ->selectRaw("SUM(CASE WHEN payment_attempts.status = 'failed' THEN 1 ELSE 0 END) as error_count")
            ->first();

        $total = (int) $stats->total;
        $successCount = (int) $stats->success_count;
        $errorCount = (int) $stats->error_count;
        $successRate = $total > 0 ? round(($successCount / $total) * 100, 2) : 0;
        $errorRate = $total > 0 ? round(($errorCount / $total) * 100, 2) : 0;

        return [
            'total' => $total,
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'success_rate' => $successRate,
            'error_rate' => $errorRate,
        ];
    }

    public function getErrorBreakdown(int|string $merchantId, string $connectorName): array
    {
        $errors = DB::table('payment_attempts')
            ->join('payment_intents', 'payment_attempts.payment_intent_id', '=', 'payment_intents.id')
            ->where('payment_intents.merchant_account_id', $merchantId)
            ->where('payment_attempts.connector', $connectorName)
            ->where('payment_attempts.status', 'failed')
            ->where('payment_attempts.created_at', '>=', now()->subDays(7))
            ->whereNotNull('payment_attempts.error_code')
            ->selectRaw('payment_attempts.error_code as code')
            ->selectRaw('payment_attempts.error_message as message')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('MAX(payment_attempts.created_at) as last_occurrence')
            ->groupBy('payment_attempts.error_code', 'payment_attempts.error_message')
            ->orderByDesc('count')
            ->limit(20)
            ->get();

        return $errors->map(fn ($row) => [
            'code' => $row->code,
            'message' => $row->message,
            'count' => (int) $row->count,
            'last_occurrence' => $row->last_occurrence,
        ])->all();
    }
}
