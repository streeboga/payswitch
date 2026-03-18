<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\StoreDashboardRoutingRuleRequest;
use App\Http\Requests\Dashboard\UpdateDashboardRoutingRuleRequest;
use App\Http\Resources\RoutingRuleResource;
use App\Services\RoutingRuleService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

#[Group('Dashboard Routing Rules', description: 'Routing rule management for the dashboard', weight: 14)]
final class DashboardRoutingRuleController extends Controller
{
    public function __construct(
        private readonly RoutingRuleService $routingRuleService,
    ) {}

    /**
     * List routing rules
     *
     * Retrieve all routing rules for the current merchant.
     */
    #[QueryParameter('page[size]', type: 'integer', description: 'Items per page (max 100)', example: 20)]
    #[QueryParameter('page[number]', type: 'integer', description: 'Page number', example: 1)]
    #[Response(200, description: 'Paginated routing rule list')]
    public function index(Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        Gate::authorize('routing-rule.viewAny', [$merchantId]);

        $paginator = $this->routingRuleService->paginateByMerchant(
            $merchantId,
            (int) $request->input('page.size', 20),
        );

        return RoutingRuleResource::jsonApiCollection($paginator, $request);
    }

    /**
     * Create routing rule
     *
     * Create a new routing rule for the current merchant.
     */
    #[Response(201, description: 'Routing rule created')]
    #[Response(422, description: 'Validation error')]
    public function store(StoreDashboardRoutingRuleRequest $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        Gate::authorize('routing-rule.create', [$merchantId]);

        $validated = $request->validated();

        $rule = $this->routingRuleService->create($merchantId, $validated);

        return (new RoutingRuleResource($rule))
            ->withStatus(201)
            ->withHeader('Location', "/api/v1/dashboard/routing-rules/{$rule->key}")
            ->toResponse($request);
    }

    /**
     * Get routing rule
     *
     * Retrieve a single routing rule.
     */
    #[PathParameter('ruleKey', description: 'Routing rule public key', example: 'rr_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Routing rule details')]
    #[Response(404, description: 'Routing rule not found')]
    public function show(string $ruleKey, Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        Gate::authorize('routing-rule.view', [$merchantId]);
        $rule = $this->routingRuleService->findByKey($ruleKey, $merchantId);

        return (new RoutingRuleResource($rule))->toResponse($request);
    }

    /**
     * Update routing rule
     *
     * Update routing rule configuration.
     */
    #[PathParameter('ruleKey', description: 'Routing rule public key', example: 'rr_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Routing rule updated')]
    #[Response(404, description: 'Routing rule not found')]
    public function update(string $ruleKey, UpdateDashboardRoutingRuleRequest $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        Gate::authorize('routing-rule.update', [$merchantId]);

        $validated = $request->validated();

        $rule = $this->routingRuleService->update($ruleKey, $merchantId, $validated);

        return (new RoutingRuleResource($rule))->toResponse($request);
    }

    /**
     * Delete routing rule
     *
     * Remove a routing rule.
     */
    #[PathParameter('ruleKey', description: 'Routing rule public key', example: 'rr_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(204, description: 'Routing rule deleted')]
    #[Response(404, description: 'Routing rule not found')]
    public function destroy(string $ruleKey, Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        Gate::authorize('routing-rule.delete', [$merchantId]);
        $this->routingRuleService->delete($ruleKey, $merchantId);

        return response()->json(null, 204);
    }
}
