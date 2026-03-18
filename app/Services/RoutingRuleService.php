<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Contracts\MerchantRepositoryInterface;
use App\Repositories\Contracts\RoutingRuleRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\RoutingRule;

final readonly class RoutingRuleService
{
    public function __construct(
        private MerchantRepositoryInterface $merchantRepository,
        private RoutingRuleRepositoryInterface $routingRuleRepository,
    ) {}

    public function findMerchant(string $merchantKey): MerchantAccount
    {
        return $this->merchantRepository->findMerchantByKey($merchantKey);
    }

    /**
     * @return Collection<int, RoutingRule>
     */
    public function listByMerchant(int|string $merchantId): Collection
    {
        return $this->routingRuleRepository->getAllByMerchant($merchantId);
    }

    public function findByKey(string $ruleKey, int|string $merchantId): RoutingRule
    {
        return $this->routingRuleRepository->findByKey($ruleKey, $merchantId);
    }

    public function create(int|string $merchantId, array $data): RoutingRule
    {
        if (isset($data['business_profile_id']) && $data['business_profile_id']) {
            $profile = $this->merchantRepository->findProfileByKey($data['business_profile_id']);
            $data['business_profile_id'] = $profile->id;
        }

        return $this->routingRuleRepository->create([
            'merchant_account_id' => $merchantId,
            'business_profile_id' => $data['business_profile_id'] ?? null,
            'type' => $data['type'],
            'name' => $data['name'],
            'rules' => $data['rules'],
            'active' => $data['active'] ?? true,
            'priority' => $data['priority'] ?? 0,
        ]);
    }

    public function update(string $ruleKey, int|string $merchantId, array $data): RoutingRule
    {
        $rule = $this->routingRuleRepository->findByKey($ruleKey, $merchantId);

        if (isset($data['business_profile_id'])) {
            $profile = $this->merchantRepository->findProfileByKey($data['business_profile_id']);
            $data['business_profile_id'] = $profile->id;
        }

        $this->routingRuleRepository->update($rule, $data);

        return $rule->fresh();
    }

    public function delete(string $ruleKey, int|string $merchantId): void
    {
        $rule = $this->routingRuleRepository->findByKey($ruleKey, $merchantId);
        $this->routingRuleRepository->delete($rule);
    }
}
