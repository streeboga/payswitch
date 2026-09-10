<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Builders\BusinessProfileQueryBuilder;
use App\Builders\MerchantAccountQueryBuilder;
use App\Builders\MerchantConnectorQueryBuilder;
use App\Builders\OrganizationQueryBuilder;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;

final readonly class MerchantRepository implements MerchantRepositoryInterface
{
    private function orgQuery(): OrganizationQueryBuilder
    {
        return OrganizationQueryBuilder::make();
    }

    private function merchantQuery(): MerchantAccountQueryBuilder
    {
        return MerchantAccountQueryBuilder::make();
    }

    private function connectorQuery(): MerchantConnectorQueryBuilder
    {
        return MerchantConnectorQueryBuilder::make();
    }

    private function profileQuery(): BusinessProfileQueryBuilder
    {
        return BusinessProfileQueryBuilder::make();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createOrganization(array $attributes): Organization
    {
        return Organization::create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createMerchantAccount(array $attributes): MerchantAccount
    {
        return MerchantAccount::create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createBusinessProfile(array $attributes): BusinessProfile
    {
        $profile = BusinessProfile::create($attributes);

        return $profile->load('merchantAccount');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createApiKey(array $attributes): ApiKey
    {
        return ApiKey::create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createConnector(array $attributes): MerchantConnectorAccount
    {
        return MerchantConnectorAccount::create($attributes);
    }

    public function findOrganizationByKey(string $key): Organization
    {
        return $this->orgQuery()->withMerchantCount()->whereKey($key)->firstOrFail();
    }

    public function findMerchantByKey(string $key): MerchantAccount
    {
        return $this->merchantQuery()->withOrganization()->withCounts()->whereKey($key)->firstOrFail();
    }

    public function findProfileByKey(string $key): BusinessProfile
    {
        return $this->profileQuery()->withMerchantAccount()->withCounts()->whereKey($key)->firstOrFail();
    }

    public function findConnectorByKey(string $key): MerchantConnectorAccount
    {
        return MerchantConnectorAccount::where('key', $key)->firstOrFail();
    }

    public function findConnectorByMerchantAndKey(int|string $merchantAccountId, string $connectorKey): MerchantConnectorAccount
    {
        return $this->connectorQuery()->forMerchant($merchantAccountId)->whereKey($connectorKey)->firstOrFail();
    }

    public function findConnectorByMerchantAndName(int|string $merchantAccountId, string $connectorName): ?MerchantConnectorAccount
    {
        return $this->connectorQuery()->forMerchant($merchantAccountId)->whereConnectorName($connectorName)->first();
    }

    public function findActiveConnectorByMerchantAndName(int|string $merchantAccountId, string $connectorName): ?MerchantConnectorAccount
    {
        return MerchantConnectorAccount::where('merchant_account_id', $merchantAccountId)
            ->where('disabled', false)
            ->where('connector_name', $connectorName)
            ->first();
    }

    /**
     * @return Collection<int, MerchantConnectorAccount>
     */
    public function getActiveConnectorsByMerchant(int|string $merchantAccountId): Collection
    {
        return MerchantConnectorAccount::where('merchant_account_id', $merchantAccountId)
            ->where('disabled', false)
            ->get();
    }

    /**
     * @return Collection<int, MerchantConnectorAccount>
     */
    public function getConnectorsByMerchant(int|string $merchantAccountId): Collection
    {
        return $this->connectorQuery()->forMerchant($merchantAccountId)->getQuery()->get();
    }

    /**
     * @param  array<int, string>  $excludeConnectors
     */
    public function getFirstActiveConnector(int|string $merchantAccountId, array $excludeConnectors = []): ?MerchantConnectorAccount
    {
        $query = MerchantConnectorAccount::where('merchant_account_id', $merchantAccountId)
            ->where('disabled', false);

        if (! empty($excludeConnectors)) {
            $query->whereNotIn('connector_name', $excludeConnectors);
        }

        return $query->first();
    }

    public function deleteConnector(MerchantConnectorAccount $connector): void
    {
        $connector->delete();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateConnector(MerchantConnectorAccount $connector, array $attributes): MerchantConnectorAccount
    {
        $connector->update($attributes);

        return $connector;
    }

    public function findProfileByMerchant(int|string $merchantAccountId): ?BusinessProfile
    {
        return $this->profileQuery()->forMerchant($merchantAccountId)->first();
    }

    public function findMerchantByKeyOrNull(string $key): ?MerchantAccount
    {
        return $this->merchantQuery()->whereKey($key)->first();
    }

    public function findConnectorByMerchantAndKeyOrNull(int|string $merchantAccountId, string $connectorKey): ?MerchantConnectorAccount
    {
        return $this->connectorQuery()->forMerchant($merchantAccountId)->whereKey($connectorKey)->first();
    }

    /**
     * @return Collection<int, Organization>
     */
    public function listOrganizations(): Collection
    {
        return $this->orgQuery()->withMerchantCount()->latest()->get();
    }

    /**
     * @return Collection<int, MerchantAccount>
     */
    public function listAllMerchants(): Collection
    {
        return $this->merchantQuery()->withOrganization()->withCounts()->latest()->get();
    }

    /**
     * @return Collection<int, ApiKey>
     */
    public function listApiKeysByMerchant(int|string $merchantAccountId): Collection
    {
        return ApiKey::where('merchant_account_id', $merchantAccountId)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @return LengthAwarePaginator<int, ApiKey>
     */
    public function paginateApiKeysByMerchant(int|string $merchantAccountId, int $perPage = 20): LengthAwarePaginator
    {
        return ApiKey::where('merchant_account_id', $merchantAccountId)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function revokeApiKey(int|string $merchantAccountId, string $apiKeyKey): void
    {
        // Колонки key у api_keys нет — ключ целиком не хранится, только его
        // хэш и префикс. На Postgres такой запрос падал пятисоткой, и отозвать
        // ключ через API было нельзя вовсе; sqlite в тестах молчал, потому что
        // считает "key" строковым литералом, а не именем колонки.
        $apiKey = ApiKey::where('merchant_account_id', $merchantAccountId)
            ->where('id', (int) $apiKeyKey)
            ->firstOrFail();
        $apiKey->revoke();
    }

    /**
     * @return Collection<int, BusinessProfile>
     */
    public function listProfilesByMerchant(int|string $merchantAccountId): Collection
    {
        return $this->profileQuery()->withMerchantAccount()->withCounts()->forMerchant($merchantAccountId)->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateProfile(BusinessProfile $profile, array $attributes): BusinessProfile
    {
        $profile->update($attributes);

        return $profile->refresh()->load('merchantAccount');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateOrganization(Organization $org, array $attributes): Organization
    {
        $org->update($attributes);

        return $org->refresh()->loadCount('merchantAccounts');
    }

    public function deleteOrganization(Organization $org): void
    {
        $org->delete();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateMerchant(MerchantAccount $merchant, array $attributes): MerchantAccount
    {
        $merchant->update($attributes);

        return $merchant->refresh()->load('organization')->loadCount(['businessProfiles', 'connectorAccounts']);
    }

    public function deleteMerchant(MerchantAccount $merchant): void
    {
        $merchant->delete();
    }

    public function deleteProfile(BusinessProfile $profile): void
    {
        $profile->delete();
    }
}
