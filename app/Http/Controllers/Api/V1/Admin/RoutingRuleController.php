<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Requests\Api\Admin\StoreRoutingRuleRequest;
use App\Http\Requests\Api\Admin\UpdateRoutingRuleRequest;
use App\Http\Resources\RoutingRuleResource;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use App\Repositories\Contracts\RoutingRuleRepositoryInterface;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

#[Group(name: 'Admin > Routing Rules', weight: 15)]
final class RoutingRuleController extends Controller
{
    public function __construct(
        private readonly MerchantRepositoryInterface $merchantRepository,
        private readonly RoutingRuleRepositoryInterface $routingRuleRepository,
    ) {}

    /**
     * Create a routing rule.
     *
     * Creates a new routing rule for the specified merchant account.
     */
    public function store(StoreRoutingRuleRequest $request, string $merchantKey): JsonResponse
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
        $attrs = $request->validatedAttributes();

        $profileId = null;
        if (! empty($attrs['business_profile_id'])) {
            $profile = $this->merchantRepository->findProfileByKey($attrs['business_profile_id']);
            $profileId = $profile->id;
        }

        $rule = $this->routingRuleRepository->create([
            'merchant_account_id' => $merchant->id,
            'business_profile_id' => $profileId,
            'type' => $attrs['type'],
            'name' => $attrs['name'],
            'rules' => $attrs['rules'],
            'active' => $attrs['active'] ?? true,
            'priority' => $attrs['priority'] ?? 0,
        ]);

        return (new RoutingRuleResource($rule))
            ->withStatus(201)
            ->withHeader('Location', url("/api/v1/merchants/{$merchantKey}/routing-rules/{$rule->key}"))
            ->toResponse($request);
    }

    /**
     * List routing rules.
     *
     * Returns all routing rules for the specified merchant account.
     */
    public function index(string $merchantKey, Request $request): JsonResponse
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
        $rules = $this->routingRuleRepository->getAllByMerchant($merchant->id);

        return RoutingRuleResource::jsonApiList($rules, $request);
    }

    /**
     * Get a routing rule.
     *
     * Retrieves the details of a specific routing rule.
     */
    public function show(string $merchantKey, string $ruleKey, Request $request): JsonResponse
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
        $rule = $this->routingRuleRepository->findByKey($ruleKey, $merchant->id);

        return (new RoutingRuleResource($rule))->toResponse($request);
    }

    /**
     * Update a routing rule.
     *
     * Updates an existing routing rule's configuration. Only provided fields are updated.
     */
    public function update(UpdateRoutingRuleRequest $request, string $merchantKey, string $ruleKey): JsonResponse
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
        $rule = $this->routingRuleRepository->findByKey($ruleKey, $merchant->id);

        $attrs = $request->validatedAttributes();
        $updateData = collect($attrs)->only(['type', 'name', 'rules', 'active', 'priority'])->toArray();

        if (isset($attrs['business_profile_id'])) {
            $profile = $this->merchantRepository->findProfileByKey($attrs['business_profile_id']);
            $updateData['business_profile_id'] = $profile->id;
        }

        $this->routingRuleRepository->update($rule, $updateData);

        return (new RoutingRuleResource($rule->fresh()))->toResponse($request);
    }

    /**
     * Delete a routing rule.
     *
     * Permanently removes a routing rule from the merchant account.
     */
    public function destroy(string $merchantKey, string $ruleKey): JsonResponse
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
        $rule = $this->routingRuleRepository->findByKey($ruleKey, $merchant->id);
        $this->routingRuleRepository->delete($rule);

        return response()->json(null, 204);
    }
}
