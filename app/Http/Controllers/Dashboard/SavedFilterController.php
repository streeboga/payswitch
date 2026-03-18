<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\StoreSavedFilterRequest;
use App\Http\Resources\SavedFilterResource;
use App\Services\SavedFilterService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Dashboard Saved Filters', description: 'Saved filter presets', weight: 27)]
final class SavedFilterController extends Controller
{
    public function __construct(
        private readonly SavedFilterService $savedFilterService,
    ) {}

    /**
     * List saved filters
     *
     * Retrieve saved filters for the current user.
     */
    #[Response(200, description: 'Saved filter list')]
    public function index(Request $request): JsonResponse
    {
        $filters = $this->savedFilterService->list($request->user()->id);

        return SavedFilterResource::jsonApiList($filters, $request);
    }

    /**
     * Create saved filter
     *
     * Save a named filter preset.
     */
    #[Response(201, description: 'Filter saved')]
    #[Response(422, description: 'Validation error')]
    public function store(StoreSavedFilterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $filter = $this->savedFilterService->create(
            $request->user()->id,
            $validated,
        );

        return (new SavedFilterResource($filter))->withStatus(201)->toResponse($request);
    }

    /**
     * Delete saved filter
     *
     * Remove a saved filter.
     */
    #[PathParameter('filterId', description: 'Saved filter ID')]
    #[Response(204, description: 'Filter deleted')]
    public function destroy(string $filterId, Request $request): JsonResponse
    {
        $this->savedFilterService->delete($filterId, $request->user()->id);

        return response()->json(null, 204);
    }
}
