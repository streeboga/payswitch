<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\DataTransferObjects\Admin\CreateConnectorData;
use App\DataTransferObjects\Admin\UpdateConnectorData;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConnectorResource;
use App\Services\ConnectorService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Dashboard Connectors', description: 'Connector management for the dashboard', weight: 13)]
final class DashboardConnectorController extends Controller
{
    public function __construct(
        private readonly ConnectorService $connectorService,
    ) {}

    /**
     * List connectors
     *
     * Retrieve all payment connectors for the current merchant.
     */
    #[Response(200, description: 'Connector list')]
    public function index(Request $request): JsonResponse
    {
        $merchantKey = $request->attributes->get('merchant_key');
        $connectors = $this->connectorService->list($merchantKey);

        return ConnectorResource::jsonApiList($connectors, $request);
    }

    /**
     * Create connector
     *
     * Add a new payment connector to the current merchant.
     */
    #[Response(201, description: 'Connector created')]
    #[Response(422, description: 'Validation error')]
    public function store(Request $request): JsonResponse
    {
        $merchantKey = $request->attributes->get('merchant_key');

        $validated = $request->validate([
            'data.attributes.connector_name' => 'required|string',
            'data.attributes.connector_type' => 'required|string',
            'data.attributes.connector_account_details' => 'required|array',
            'data.attributes.profile_id' => 'sometimes|string',
            'data.attributes.payment_methods_enabled' => 'sometimes|array',
            'data.attributes.test_mode' => 'sometimes|boolean',
        ]);

        $attrs = $validated['data']['attributes'];

        $connector = $this->connectorService->create(
            $merchantKey,
            CreateConnectorData::from($attrs),
        );

        return (new ConnectorResource($connector))
            ->withStatus(201)
            ->withHeader('Location', "/api/v1/dashboard/connectors/{$connector->key}")
            ->toResponse($request);
    }

    /**
     * Get connector
     *
     * Retrieve connector details.
     */
    #[PathParameter('connectorKey', description: 'Connector public key', example: 'mca_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Connector details')]
    #[Response(404, description: 'Connector not found')]
    public function show(string $connectorKey, Request $request): JsonResponse
    {
        $merchantKey = $request->attributes->get('merchant_key');
        $connector = $this->connectorService->find($merchantKey, $connectorKey);

        return (new ConnectorResource($connector))->toResponse($request);
    }

    /**
     * Update connector
     *
     * Update connector configuration.
     */
    #[PathParameter('connectorKey', description: 'Connector public key', example: 'mca_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Connector updated')]
    #[Response(404, description: 'Connector not found')]
    public function update(string $connectorKey, Request $request): JsonResponse
    {
        $merchantKey = $request->attributes->get('merchant_key');

        $validated = $request->validate([
            'data.attributes.connector_account_details' => 'sometimes|array',
            'data.attributes.payment_methods_enabled' => 'sometimes|array',
            'data.attributes.test_mode' => 'sometimes|boolean',
            'data.attributes.disabled' => 'sometimes|boolean',
        ]);

        $attrs = $validated['data']['attributes'] ?? [];

        $connector = $this->connectorService->update(
            $merchantKey,
            $connectorKey,
            UpdateConnectorData::from($attrs),
        );

        return (new ConnectorResource($connector))->toResponse($request);
    }

    /**
     * Delete connector
     *
     * Remove a connector from the current merchant.
     */
    #[PathParameter('connectorKey', description: 'Connector public key', example: 'mca_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(204, description: 'Connector deleted')]
    #[Response(404, description: 'Connector not found')]
    public function destroy(string $connectorKey, Request $request): JsonResponse
    {
        $merchantKey = $request->attributes->get('merchant_key');
        $this->connectorService->delete($merchantKey, $connectorKey);

        return response()->json(null, 204);
    }
}
