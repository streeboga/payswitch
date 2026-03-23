<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Contracts\MerchantRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\BusinessProfile;

final readonly class BusinessProfileService
{
    public function __construct(
        private MerchantRepositoryInterface $merchantRepository,
    ) {}

    /**
     * @return Collection<int, BusinessProfile>
     */
    public function listByMerchantId(int|string $merchantId): Collection
    {
        return $this->merchantRepository->listProfilesByMerchant($merchantId);
    }

    /**
     * @return Collection<int, BusinessProfile>
     */
    public function listByMerchantKey(string $merchantKey): Collection
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);

        return $this->merchantRepository->listProfilesByMerchant($merchant->id);
    }

    public function findByKey(string $profileKey): BusinessProfile
    {
        return $this->merchantRepository->findProfileByKey($profileKey);
    }

    public function create(int|string $merchantId, ?string $name, ?string $webhookUrl): BusinessProfile
    {
        return $this->merchantRepository->createBusinessProfile([
            'merchant_account_id' => $merchantId,
            'name' => $name ?? '',
            'webhook_url' => $webhookUrl,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(string $profileKey, array $attributes): BusinessProfile
    {
        $profile = $this->merchantRepository->findProfileByKey($profileKey);

        return $this->merchantRepository->updateProfile($profile, $attributes);
    }

    public function delete(string $profileKey): void
    {
        $profile = $this->merchantRepository->findProfileByKey($profileKey);
        $this->merchantRepository->deleteProfile($profile);
    }
}
