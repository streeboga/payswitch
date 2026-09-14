<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Contracts\MerchantRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
     * Профиль ищется только среди профилей мерчанта: ключ профиля — не
     * секрет, и поиск по нему одному отдавал admin мерчанта A профиль B.
     */
    public function findByKey(string $profileKey, int|string $merchantId): BusinessProfile
    {
        return $this->merchantRepository->findProfileByMerchantAndKey($merchantId, $profileKey)
            ?? throw (new ModelNotFoundException)->setModel(BusinessProfile::class, [$profileKey]);
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
    public function update(string $profileKey, int|string $merchantId, array $attributes): BusinessProfile
    {
        return $this->merchantRepository->updateProfile($this->findByKey($profileKey, $merchantId), $attributes);
    }

    public function delete(string $profileKey, int|string $merchantId): void
    {
        $this->merchantRepository->deleteProfile($this->findByKey($profileKey, $merchantId));
    }
}
