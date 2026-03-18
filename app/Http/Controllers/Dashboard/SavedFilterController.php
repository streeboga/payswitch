<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\SavedFilter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Dashboard Saved Filters', description: 'Saved filter presets', weight: 27)]
final class SavedFilterController extends Controller
{
    /**
     * List saved filters
     *
     * Retrieve saved filters for the current user.
     */
    #[Response(200, description: 'Saved filter list')]
    public function index(Request $request): JsonResponse
    {
        $filters = SavedFilter::where('user_id', $request->user()->id)
            ->orderBy('sort_order')
            ->get();

        $items = $filters->map(fn (SavedFilter $f) => [
            'type' => 'saved-filters',
            'id' => (string) $f->id,
            'attributes' => [
                'table_name' => $f->table_name,
                'name' => $f->name,
                'filters' => $f->filters,
                'is_preset' => $f->is_preset,
                'sort_order' => $f->sort_order,
            ],
        ])->toArray();

        return response()->json(
            ['data' => $items],
            200,
            ['Content-Type' => 'application/vnd.api+json'],
        );
    }

    /**
     * Create saved filter
     *
     * Save a named filter preset.
     */
    #[Response(201, description: 'Filter saved')]
    #[Response(422, description: 'Validation error')]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'data.attributes.table_name' => 'required|string|in:payments,refunds,disputes,webhook-events,customers',
            'data.attributes.name' => 'required|string|max:100',
            'data.attributes.filters' => 'required|array',
        ]);

        $filter = SavedFilter::create([
            'user_id' => $request->user()->id,
            ...$validated['data']['attributes'],
        ]);

        return response()->json([
            'data' => [
                'type' => 'saved-filters',
                'id' => (string) $filter->id,
                'attributes' => [
                    'table_name' => $filter->table_name,
                    'name' => $filter->name,
                    'filters' => $filter->filters,
                ],
            ],
        ], 201, ['Content-Type' => 'application/vnd.api+json']);
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
        $filter = SavedFilter::where('user_id', $request->user()->id)
            ->findOrFail($filterId);

        $filter->delete();

        return response()->json(null, 204);
    }
}
