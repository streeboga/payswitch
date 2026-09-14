<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Contracts\MerchantRepositoryInterface;
use App\Repositories\Contracts\RoutingRuleRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
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

    /**
     * @return LengthAwarePaginator<int, RoutingRule>
     */
    public function paginateByMerchant(int|string $merchantId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->routingRuleRepository->paginateByMerchant($merchantId, $perPage);
    }

    public function findByKey(string $ruleKey, int|string $merchantId): RoutingRule
    {
        return $this->routingRuleRepository->findByKey($ruleKey, $merchantId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int|string $merchantId, array $data): RoutingRule
    {
        if (isset($data['business_profile_id']) && $data['business_profile_id']) {
            $data['business_profile_id'] = $this->resolveProfileId($data['business_profile_id'], $merchantId);
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

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(string $ruleKey, int|string $merchantId, array $data): RoutingRule
    {
        $rule = $this->routingRuleRepository->findByKey($ruleKey, $merchantId);

        if (isset($data['business_profile_id'])) {
            $data['business_profile_id'] = $this->resolveProfileId($data['business_profile_id'], $merchantId);
        }

        $this->routingRuleRepository->update($rule, $data);

        $rule->refresh();

        return $rule;
    }

    public function delete(string $ruleKey, int|string $merchantId): void
    {
        $rule = $this->routingRuleRepository->findByKey($ruleKey, $merchantId);
        $this->routingRuleRepository->delete($rule);
    }

    /**
     * Чужой профиль не принимается: иначе правило мерчанта A привязывается
     * к профилю мерчанта B.
     */
    private function resolveProfileId(string $profileKey, int|string $merchantId): int
    {
        return $this->merchantRepository->findProfileByMerchantAndKey($merchantId, $profileKey)->id
            ?? throw ValidationException::withMessages(['business_profile_id' => 'Профиль не найден у этого мерчанта.']);
    }
}
