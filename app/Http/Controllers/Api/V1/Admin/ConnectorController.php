<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Requests\Api\Admin\StoreConnectorRequest;
use App\Http\Requests\Api\Admin\UpdateConnectorRequest;
use App\Http\Resources\ConnectorResource;
use App\Services\ConnectorService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

#[Group(name: 'Admin > Connectors', weight: 14)]
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
    public function destroy(string $merchantKey, string $connectorKey): JsonResponse
    {
        $this->connectorService->delete($merchantKey, $connectorKey);

        return response()->json(null, 204);
    }
}
