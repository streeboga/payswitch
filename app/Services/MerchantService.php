<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Contracts\MerchantRepositoryInterface;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Support\IdGenerator;

final class MerchantService
{
    public function __construct(
        private MerchantRepositoryInterface $merchantRepository,
    ) {}

    public function createOrganization(array $data): Organization
    {
        return $this->merchantRepository->createOrganization([
            'name' => $data['name'],
        ]);
    }

    public function findMerchant(string $merchantKey): MerchantAccount
    {
        return $this->merchantRepository->findMerchantByKey($merchantKey);
    }

    public function createMerchantAccount(array $data): MerchantAccount
    {
        $organization = $this->merchantRepository->findOrganizationByKey($data['organization_id']);

        return $this->merchantRepository->createMerchantAccount([
            'org_id' => $organization->id,
            'name' => $data['name'],
        ]);
    }

    public function findProfile(string $profileKey): BusinessProfile
    {
        return $this->merchantRepository->findProfileByKey($profileKey);
    }

    public function createBusinessProfile(array $data): BusinessProfile
    {
        $merchant = $this->merchantRepository->findMerchantByKey($data['merchant_id']);

        return $this->merchantRepository->createBusinessProfile([
            'merchant_account_id' => $merchant->id,
            'webhook_url' => $data['webhook_url'] ?? null,
        ]);
    }

    /**
     * @return array{apiKey: ApiKey, rawKey: string}
     */
    public function createApiKey(string $merchantKey, ?string $name = null): array
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);

        $rawKey = IdGenerator::apiKey(config('payswitch.environment', 'sandbox'));

        $apiKey = $this->merchantRepository->createApiKey([
            'merchant_account_id' => $merchant->id,
            'key_hash' => bcrypt($rawKey),
            'key_prefix' => substr($rawKey, 0, 20),
            'name' => $name,
        ]);

        return ['apiKey' => $apiKey, 'rawKey' => $rawKey];
    }

    public function revokeApiKey(string $merchantKey, string $keyId): void
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
        $apiKey = $merchant->apiKeys()->findOrFail($keyId);
        $apiKey->revoke();
    }
}
