<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;

#[Group('Dashboard Connector Health', description: 'Connector health monitoring', weight: 24)]
final class ConnectorHealthController extends Controller
{
    /**
     * Get connector health snapshot
     *
     * Current health metrics for a connector based on recent payment attempts.
     */
    #[PathParameter('connectorKey', description: 'Connector public key')]
    #[QueryParameter('filter[period]', type: 'string', description: 'Time period', enum: ['24h', '7d', '30d'])]
    #[Response(200, description: 'Connector health snapshot')]
    #[Response(404, description: 'Connector not found')]
    public function health(string $connectorKey, Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');

        $mca = MerchantConnectorAccount::where('merchant_account_id', $merchantId)
            ->where('key', $connectorKey)
            ->firstOrFail();

        $period = $request->input('filter.period', '24h');
        $hours = match ($period) {
            '7d' => 168,
            '30d' => 720,
            default => 24,
        };

        $since = now()->subHours($hours);

        $stats = DB::table('payment_attempts')
            ->join('payment_intents', 'payment_attempts.payment_intent_id', '=', 'payment_intents.id')
            ->where('payment_intents.merchant_account_id', $merchantId)
            ->where('payment_attempts.connector', $mca->connector_name)
            ->where('payment_attempts.created_at', '>=', $since)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN payment_attempts.status = 'succeeded' THEN 1 ELSE 0 END) as success_count")
            ->selectRaw("SUM(CASE WHEN payment_attempts.status = 'failed' THEN 1 ELSE 0 END) as error_count")
            ->first();

        $total = (int) $stats->total;
        $successRate = $total > 0 ? round(((int) $stats->success_count / $total) * 100, 2) : 0;
        $errorRate = $total > 0 ? round(((int) $stats->error_count / $total) * 100, 2) : 0;

        return response()->json([
            'data' => [
                'type' => 'connector-health',
                'id' => $mca->key,
                'attributes' => [
                    'connector_name' => $mca->connector_name,
                    'period' => $period,
                    'total_attempts' => $total,
                    'success_count' => (int) $stats->success_count,
                    'error_count' => (int) $stats->error_count,
                    'success_rate' => $successRate,
                    'error_rate' => $errorRate,
                ],
            ],
        ], 200, ['Content-Type' => 'application/vnd.api+json']);
    }

    /**
     * Get connector error breakdown
     *
     * Recent errors grouped by error code for a connector.
     */
    #[PathParameter('connectorKey', description: 'Connector public key')]
    #[Response(200, description: 'Error breakdown')]
    public function errors(string $connectorKey, Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');

        $mca = MerchantConnectorAccount::where('merchant_account_id', $merchantId)
            ->where('key', $connectorKey)
            ->firstOrFail();

        $errors = DB::table('payment_attempts')
            ->join('payment_intents', 'payment_attempts.payment_intent_id', '=', 'payment_intents.id')
            ->where('payment_intents.merchant_account_id', $merchantId)
            ->where('payment_attempts.connector', $mca->connector_name)
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

        $items = $errors->map(fn ($row, $i) => [
            'type' => 'connector-errors',
            'id' => (string) ($i + 1),
            'attributes' => [
                'code' => $row->code,
                'message' => $row->message,
                'count' => (int) $row->count,
                'last_occurrence' => $row->last_occurrence,
            ],
        ])->toArray();

        return response()->json(
            ['data' => $items],
            200,
            ['Content-Type' => 'application/vnd.api+json'],
        );
    }
}
