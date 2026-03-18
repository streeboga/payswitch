<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Requests\Api\Admin\StoreConnectorRequest;
use App\Http\Requests\Api\Admin\UpdateConnectorRequest;
use App\Http\Resources\ConnectorResource;
use App\Services\ConnectorService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

#[Group(name: 'Admin > Connectors', description: 'Payment connector configuration', weight: 14)]
final class ConnectorController extends Controller
{
    public function __construct(
        private readonly ConnectorService $connectorService,
    ) {}

    /**
     * Create a connector.
     *
     * Registers a new payment connector (PSP) for the specified merchant account.
     */
    #[PathParameter('merchantKey', description: 'Merchant public key', example: 'merchant_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(201, description: 'Connector created')]
    #[Response(422, description: 'Validation error')]
    public function store(StoreConnectorRequest $request, string $merchantKey): JsonResponse
    {
        $connector = $this->connectorService->create($merchantKey, $request->toDto());

        return (new ConnectorResource($connector))
            ->withStatus(201)
            ->withHeader('Location', url("/api/v1/merchants/{$merchantKey}/connectors/{$connector->key}"))
            ->toResponse($request);
    }

    /**
     * List connectors.
     *
     * Returns all payment connectors configured for the specified merchant account.
     */
    #[PathParameter('merchantKey', description: 'Merchant public key', example: 'merchant_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Connector list')]
    public function index(string $merchantKey, Request $request): JsonResponse
    {
        $connectors = $this->connectorService->list($merchantKey);

        return ConnectorResource::jsonApiList($connectors, $request);
    }

    /**
     * Get a connector.
     *
     * Retrieves the details of a specific connector.
     */
    #[PathParameter('merchantKey', description: 'Merchant public key', example: 'merchant_01jd5x7k3m9p2q4r6s8t0v')]
    #[PathParameter('connectorKey', description: 'Connector public key', example: 'mca_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Connector details')]
    #[Response(404, description: 'Connector not found')]
    public function show(string $merchantKey, string $connectorKey, Request $request): JsonResponse
    {
        $connector = $this->connectorService->find($merchantKey, $connectorKey);

        return (new ConnectorResource($connector))->toResponse($request);
    }

    /**
     * Update a connector.
     *
     * Updates an existing connector's configuration. Only provided fields are updated.
     */
    #[PathParameter('merchantKey', description: 'Merchant public key', example: 'merchant_01jd5x7k3m9p2q4r6s8t0v')]
    #[PathParameter('connectorKey', description: 'Connector public key', example: 'mca_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Connector updated')]
    #[Response(404, description: 'Connector not found')]
    public function update(UpdateConnectorRequest $request, string $merchantKey, string $connectorKey): JsonResponse
    {
        $connector = $this->connectorService->update($merchantKey, $connectorKey, $request->toDto());

        return (new ConnectorResource($connector))->toResponse($request);
    }

    /**
     * Delete a connector.
     *
     * Permanently removes a connector from the merchant account.
     */
    #[PathParameter('merchantKey', description: 'Merchant public key', example: 'merchant_01jd5x7k3m9p2q4r6s8t0v')]
    #[PathParameter('connectorKey', description: 'Connector public key', example: 'mca_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(204, description: 'Connector deleted')]
    #[Response(404, description: 'Connector not found')]
    public function destroy(string $merchantKey, string $connectorKey): JsonResponse
    {
        $this->connectorService->delete($merchantKey, $connectorKey);

        return response()->json(null, 204);
    }
}
