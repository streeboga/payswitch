<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\RoutingRuleType;
use App\Http\Controllers\Controller;
use App\Http\Resources\RoutingRuleResource;
use App\Repositories\Contracts\RoutingRuleRepositoryInterface;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Dashboard Routing Rules', description: 'Routing rule management for the dashboard', weight: 14)]
final class DashboardRoutingRuleController extends Controller
{
    public function __construct(
        private readonly RoutingRuleRepositoryInterface $routingRuleRepository,
    ) {}

    /**
     * List routing rules
     *
     * Retrieve all routing rules for the current merchant.
     */
    #[Response(200, description: 'Routing rule list')]
    public function index(Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        $rules = $this->routingRuleRepository->getAllByMerchant($merchantId);

        return RoutingRuleResource::jsonApiList($rules, $request);
    }

    /**
     * Create routing rule
     *
     * Create a new routing rule for the current merchant.
     */
    #[Response(201, description: 'Routing rule created')]
    #[Response(422, description: 'Validation error')]
    public function store(Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');

        $validated = $request->validate([
            'data.attributes.type' => ['required', Rule::enum(RoutingRuleType::class)],
            'data.attributes.name' => 'required|string|max:255',
            'data.attributes.rules' => 'required|array',
            'data.attributes.active' => 'sometimes|boolean',
            'data.attributes.priority' => 'sometimes|integer|min:0',
        ]);

        $attrs = $validated['data']['attributes'];

        $rule = $this->routingRuleRepository->create([
            'merchant_account_id' => $merchantId,
            ...$attrs,
        ]);

        return (new RoutingRuleResource($rule))
            ->withStatus(201)
            ->toResponse($request);
    }

    /**
     * Get routing rule
     *
     * Retrieve a single routing rule.
     */
    #[Response(200, description: 'Routing rule details')]
    #[Response(404, description: 'Routing rule not found')]
    public function show(string $ruleKey, Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        $rule = $this->routingRuleRepository->findByKey($ruleKey, $merchantId);

        return (new RoutingRuleResource($rule))->toResponse($request);
    }

    /**
     * Update routing rule
     *
     * Update routing rule configuration.
     */
    #[Response(200, description: 'Routing rule updated')]
    #[Response(404, description: 'Routing rule not found')]
    public function update(string $ruleKey, Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');

        $validated = $request->validate([
            'data.attributes.type' => ['sometimes', Rule::enum(RoutingRuleType::class)],
            'data.attributes.name' => 'sometimes|string|max:255',
            'data.attributes.rules' => 'sometimes|array',
            'data.attributes.active' => 'sometimes|boolean',
            'data.attributes.priority' => 'sometimes|integer|min:0',
        ]);

        $rule = $this->routingRuleRepository->findByKey($ruleKey, $merchantId);
        $rule = $this->routingRuleRepository->update($rule, $validated['data']['attributes'] ?? []);

        return (new RoutingRuleResource($rule))->toResponse($request);
    }

    /**
     * Delete routing rule
     *
     * Remove a routing rule.
     */
    #[Response(204, description: 'Routing rule deleted')]
    #[Response(404, description: 'Routing rule not found')]
    public function destroy(string $ruleKey, Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        $rule = $this->routingRuleRepository->findByKey($ruleKey, $merchantId);
        $this->routingRuleRepository->delete($rule);

        return response()->json(null, 204);
    }
}
