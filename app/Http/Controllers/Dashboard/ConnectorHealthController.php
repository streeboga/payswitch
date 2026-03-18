<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConnectorHealthResource;
use App\Services\ConnectorHealthService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

#[Group('Dashboard Connector Health', description: 'Connector health monitoring', weight: 24)]
final class ConnectorHealthController extends Controller
{
    public function __construct(
        private readonly ConnectorHealthService $connectorHealthService,
    ) {}

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
        Gate::authorize('connector-health.view', [$merchantId]);
        $mca = $this->connectorHealthService->resolveConnector($merchantId, $connectorKey);

        $period = $request->input('filter.period', '24h');
        $stats = $this->connectorHealthService->getHealthStats($merchantId, $mca->connector_name, $period);

        return (new ConnectorHealthResource([
            'key' => $mca->key,
            'connector_name' => $mca->connector_name,
            'period' => $period,
            'total_attempts' => $stats['total'],
            'success_count' => $stats['success_count'],
            'error_count' => $stats['error_count'],
            'success_rate' => $stats['success_rate'],
            'error_rate' => $stats['error_rate'],
        ]))->toResponse($request);
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
        Gate::authorize('connector-health.view', [$merchantId]);
        $mca = $this->connectorHealthService->resolveConnector($merchantId, $connectorKey);

        $errors = $this->connectorHealthService->getErrorBreakdown($merchantId, $mca->connector_name);

        $items = array_map(fn (array $row, int $i) => [
            'type' => 'connector-errors',
            'id' => (string) ($i + 1),
            'attributes' => $row,
        ], $errors, array_keys($errors));

        return response()->json(
            ['data' => $items],
            200,
            ['Content-Type' => 'application/vnd.api+json'],
        );
    }
}
