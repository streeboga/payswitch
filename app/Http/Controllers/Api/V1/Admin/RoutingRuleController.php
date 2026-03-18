<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\JsonApiResponse;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use App\Repositories\Contracts\RoutingRuleRepositoryInterface;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Streeboga\PaymentData\Models\RoutingRule;

#[Group(name: 'Admin > Routing Rules', weight: 15)]
final class RoutingRuleController extends Controller
{
    use JsonApiResponse;

    public function __construct(
        private MerchantRepositoryInterface $merchantRepository,
        private RoutingRuleRepositoryInterface $routingRuleRepository,
    ) {}

    /**
     * Create a routing rule.
     *
     * Creates a new routing rule for the specified merchant account. Routing rules determine
     * which connector processes a payment based on rule type (priority, rule_based, or volume_split).
     * Rules are evaluated in order of priority when multiple active rules exist.
     *
     * @pathParam merchantKey string required The unique key of the merchant account. Example: mer_1a2b3c4d5e
     */
    public function store(Request $request, string $merchantKey): JsonResponse
    {
        $request->validate([
            'data.attributes.type' => ['required', 'string', 'in:priority,rule_based,volume_split'],
            'data.attributes.name' => ['required', 'string', 'max:255'],
            'data.attributes.rules' => ['required', 'array'],
            'data.attributes.active' => ['sometimes', 'boolean'],
            'data.attributes.priority' => ['sometimes', 'integer', 'min:0'],
        ]);

        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
        $attrs = $request->input('data.attributes', []);

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

        return $this->jsonApiResource(
            model: $rule,
            type: 'routing_rules',
            attributes: $this->ruleAttributes($rule),
            status: 201,
            headers: ['Location' => url("/api/v1/merchants/{$merchantKey}/routing-rules/{$rule->key}")],
        );
    }

    /**
     * List routing rules.
     *
     * Returns all routing rules for the specified merchant account, ordered by priority
     * in descending order. Includes both active and inactive rules.
     *
     * @pathParam merchantKey string required The unique key of the merchant account. Example: mer_1a2b3c4d5e
     */
    public function index(string $merchantKey): JsonResponse
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);

        $rules = $this->routingRuleRepository->getAllByMerchant($merchant->id);

        return $this->jsonApiCollection(
            models: $rules,
            type: 'routing_rules',
            attributeMapper: fn (RoutingRule $rule) => $this->ruleAttributes($rule),
        );
    }

    /**
     * Get a routing rule.
     *
     * Retrieves the details of a specific routing rule including its type, evaluation rules,
     * active status, and priority.
     *
     * @pathParam merchantKey string required The unique key of the merchant account. Example: mer_1a2b3c4d5e
     * @pathParam ruleKey string required The unique key of the routing rule. Example: rr_1a2b3c4d5e
     */
    public function show(string $merchantKey, string $ruleKey): JsonResponse
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);

        $rule = $this->routingRuleRepository->findByKey($ruleKey, $merchant->id);

        return $this->jsonApiResource(
            model: $rule,
            type: 'routing_rules',
            attributes: $this->ruleAttributes($rule),
        );
    }

    /**
     * Update a routing rule.
     *
     * Updates an existing routing rule's configuration. Allows changing the rule type,
     * name, evaluation rules, active status, priority, or associated business profile.
     * Only the provided fields are updated.
     *
     * @pathParam merchantKey string required The unique key of the merchant account. Example: mer_1a2b3c4d5e
     * @pathParam ruleKey string required The unique key of the routing rule. Example: rr_1a2b3c4d5e
     */
    public function update(Request $request, string $merchantKey, string $ruleKey): JsonResponse
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);

        $rule = $this->routingRuleRepository->findByKey($ruleKey, $merchant->id);

        $attrs = $request->input('data.attributes', []);

        $updateData = collect($attrs)->only([
            'type',
            'name',
            'rules',
            'active',
            'priority',
        ])->toArray();

        if (isset($attrs['business_profile_id'])) {
            $profile = $this->merchantRepository->findProfileByKey($attrs['business_profile_id']);
            $updateData['business_profile_id'] = $profile->id;
        }

        $this->routingRuleRepository->update($rule, $updateData);
        $rule->refresh();

        return $this->jsonApiResource(
            model: $rule,
            type: 'routing_rules',
            attributes: $this->ruleAttributes($rule),
        );
    }

    /**
     * Delete a routing rule.
     *
     * Permanently removes a routing rule from the merchant account. If the deleted rule
     * was the only active rule, payments will fall back to the default connector selection.
     *
     * @pathParam merchantKey string required The unique key of the merchant account. Example: mer_1a2b3c4d5e
     * @pathParam ruleKey string required The unique key of the routing rule. Example: rr_1a2b3c4d5e
     */
    public function destroy(string $merchantKey, string $ruleKey): JsonResponse
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);

        $rule = $this->routingRuleRepository->findByKey($ruleKey, $merchant->id);

        $this->routingRuleRepository->delete($rule);

        return $this->jsonApiNoContent();
    }

    private function ruleAttributes(RoutingRule $rule): array
    {
        return [
            'type' => $rule->type,
            'name' => $rule->name,
            'rules' => $rule->rules,
            'active' => $rule->active,
            'priority' => $rule->priority,
            'created_at' => $rule->created_at->toIso8601String(),
        ];
    }
}
