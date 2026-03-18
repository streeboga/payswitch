<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\JsonApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\RoutingRule;

final class RoutingRuleController extends Controller
{
    use JsonApiResponse;

    public function store(Request $request, string $merchantKey): JsonResponse
    {
        $request->validate([
            'data.attributes.type' => ['required', 'string', 'in:priority,rule_based,volume_split'],
            'data.attributes.name' => ['required', 'string', 'max:255'],
            'data.attributes.rules' => ['required', 'array'],
            'data.attributes.active' => ['sometimes', 'boolean'],
            'data.attributes.priority' => ['sometimes', 'integer', 'min:0'],
        ]);

        $merchant = MerchantAccount::where('key', $merchantKey)->firstOrFail();
        $attrs = $request->input('data.attributes', []);

        $profileId = null;
        if (! empty($attrs['business_profile_id'])) {
            $profile = BusinessProfile::where('key', $attrs['business_profile_id'])->firstOrFail();
            $profileId = $profile->id;
        }

        $rule = RoutingRule::create([
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

    public function index(string $merchantKey): JsonResponse
    {
        $merchant = MerchantAccount::where('key', $merchantKey)->firstOrFail();

        $rules = RoutingRule::where('merchant_account_id', $merchant->id)
            ->orderByDesc('priority')
            ->get();

        return $this->jsonApiCollection(
            models: $rules,
            type: 'routing_rules',
            attributeMapper: fn (RoutingRule $rule) => $this->ruleAttributes($rule),
        );
    }

    public function show(string $merchantKey, string $ruleKey): JsonResponse
    {
        $merchant = MerchantAccount::where('key', $merchantKey)->firstOrFail();

        $rule = RoutingRule::where('merchant_account_id', $merchant->id)
            ->where('key', $ruleKey)
            ->firstOrFail();

        return $this->jsonApiResource(
            model: $rule,
            type: 'routing_rules',
            attributes: $this->ruleAttributes($rule),
        );
    }

    public function update(Request $request, string $merchantKey, string $ruleKey): JsonResponse
    {
        $merchant = MerchantAccount::where('key', $merchantKey)->firstOrFail();

        $rule = RoutingRule::where('merchant_account_id', $merchant->id)
            ->where('key', $ruleKey)
            ->firstOrFail();

        $attrs = $request->input('data.attributes', []);

        $updateData = collect($attrs)->only([
            'type',
            'name',
            'rules',
            'active',
            'priority',
        ])->toArray();

        if (isset($attrs['business_profile_id'])) {
            $profile = BusinessProfile::where('key', $attrs['business_profile_id'])->firstOrFail();
            $updateData['business_profile_id'] = $profile->id;
        }

        $rule->update($updateData);
        $rule->refresh();

        return $this->jsonApiResource(
            model: $rule,
            type: 'routing_rules',
            attributes: $this->ruleAttributes($rule),
        );
    }

    public function destroy(string $merchantKey, string $ruleKey): JsonResponse
    {
        $merchant = MerchantAccount::where('key', $merchantKey)->firstOrFail();

        $rule = RoutingRule::where('merchant_account_id', $merchant->id)
            ->where('key', $ruleKey)
            ->firstOrFail();

        $rule->delete();

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
