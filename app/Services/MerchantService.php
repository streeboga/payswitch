<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\Admin\CreateApiKeyData;
use App\DataTransferObjects\Admin\CreateBusinessProfileData;
use App\DataTransferObjects\Admin\CreateMerchantAccountData;
use App\DataTransferObjects\Admin\CreateOrganizationData;
use App\DataTransferObjects\Admin\UpdateMerchantAccountData;
use App\DataTransferObjects\Admin\UpdateOrganizationData;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Support\IdGenerator;

final readonly class MerchantService
{
    public function __construct(
        private MerchantRepositoryInterface $merchantRepository,
    ) {}

    /**
     * @return Collection<int, Organization>
     */
    public function listOrganizations(): Collection
    {
        return $this->merchantRepository->listOrganizations();
    }

    public function findOrganization(string $orgKey): Organization
    {
        return $this->merchantRepository->findOrganizationByKey($orgKey);
    }

    /**
     * @return Collection<int, MerchantAccount>
     */
    public function listAllMerchants(): Collection
    {
        return $this->merchantRepository->listAllMerchants();
    }

    /**
     * @return Collection<int, MerchantAccount>
     */
    public function listMerchantsByOrganization(string $orgKey): Collection
    {
        $org = $this->merchantRepository->findOrganizationByKey($orgKey);

        return $org->load('merchantAccounts.organization')->merchantAccounts;
    }

    public function createOrganization(CreateOrganizationData $dto): Organization
    {
        return $this->merchantRepository->createOrganization($dto->toArray());
    }

    public function findMerchant(string $merchantKey): MerchantAccount
    {
        return $this->merchantRepository->findMerchantByKey($merchantKey);
    }

    public function createMerchantAccount(CreateMerchantAccountData $dto): MerchantAccount
    {
        $organization = $this->merchantRepository->findOrganizationByKey($dto->organization_id);

        return $this->merchantRepository->createMerchantAccount([
            'org_id' => $organization->id,
            'name' => $dto->name,
        ]);
    }

    public function findProfile(string $profileKey): BusinessProfile
    {
        return $this->merchantRepository->findProfileByKey($profileKey);
    }

    public function createBusinessProfile(CreateBusinessProfileData $dto): BusinessProfile
    {
        $merchant = $this->merchantRepository->findMerchantByKey($dto->merchant_id);

        return $this->merchantRepository->createBusinessProfile([
            'merchant_account_id' => $merchant->id,
            'name' => $dto->name ?? '',
            'webhook_url' => $dto->webhook_url,
        ]);
    }

    /**
     * @return Collection<int, ApiKey>
     */
    public function listApiKeys(int|string $merchantId): Collection
    {
        return $this->merchantRepository->listApiKeysByMerchant($merchantId);
    }

    /**
     * @return LengthAwarePaginator<int, ApiKey>
     */
    public function paginateApiKeys(int|string $merchantId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->merchantRepository->paginateApiKeysByMerchant($merchantId, $perPage);
    }

    /**
     * @return array{apiKey: ApiKey, rawKey: string}
     */
    public function createApiKey(string $merchantKey, CreateApiKeyData $dto): array
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);

        $rawKey = IdGenerator::apiKey(config('payswitch.environment', 'sandbox'));

        $apiKey = $this->merchantRepository->createApiKey([
            'merchant_account_id' => $merchant->id,
            'key_hash' => hash('sha256', $rawKey),
            'key_prefix' => substr($rawKey, 0, 20),
            'name' => $dto->name,
            'type' => $dto->type,
        ]);

        return ['apiKey' => $apiKey, 'rawKey' => $rawKey];
    }

    public function revokeApiKey(string $merchantKey, string $keyId): void
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
        $this->merchantRepository->revokeApiKey($merchant->id, $keyId);
    }

    public function updateOrganization(string $orgKey, UpdateOrganizationData $dto): Organization
    {
        $org = $this->merchantRepository->findOrganizationByKey($orgKey);

        return $this->merchantRepository->updateOrganization($org, $dto->toArray());
    }

    public function deleteOrganization(string $orgKey): void
    {
        $org = $this->merchantRepository->findOrganizationByKey($orgKey);
        $this->merchantRepository->deleteOrganization($org);
    }

    public function updateMerchant(string $merchantKey, UpdateMerchantAccountData $dto): MerchantAccount
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);

        return $this->merchantRepository->updateMerchant($merchant, $dto->toArray());
    }

    public function deleteMerchant(string $merchantKey): void
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
        $this->merchantRepository->deleteMerchant($merchant);
    }
}
