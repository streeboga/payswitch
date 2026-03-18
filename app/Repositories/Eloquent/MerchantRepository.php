<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\MerchantRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;

final class MerchantRepository implements MerchantRepositoryInterface
{
    public function createOrganization(array $attributes): Organization
    {
        return Organization::create($attributes);
    }

    public function createMerchantAccount(array $attributes): MerchantAccount
    {
        return MerchantAccount::create($attributes);
    }

    public function createBusinessProfile(array $attributes): BusinessProfile
    {
        return BusinessProfile::create($attributes);
    }

    public function createApiKey(array $attributes): ApiKey
    {
        return ApiKey::create($attributes);
    }

    public function createConnector(array $attributes): MerchantConnectorAccount
    {
        return MerchantConnectorAccount::create($attributes);
    }

    public function findOrganizationByKey(string $key): Organization
    {
        return Organization::where('key', $key)->firstOrFail();
    }

    public function findMerchantByKey(string $key): MerchantAccount
    {
        return MerchantAccount::where('key', $key)->firstOrFail();
    }

    public function findProfileByKey(string $key): BusinessProfile
    {
        return BusinessProfile::where('key', $key)->firstOrFail();
    }

    public function findConnectorByKey(string $key): MerchantConnectorAccount
    {
        return MerchantConnectorAccount::where('key', $key)->firstOrFail();
    }

    public function findConnectorByMerchantAndKey(int|string $merchantAccountId, string $connectorKey): MerchantConnectorAccount
    {
        return MerchantConnectorAccount::where('merchant_account_id', $merchantAccountId)
            ->where('key', $connectorKey)
            ->firstOrFail();
    }

    public function findConnectorByMerchantAndName(int|string $merchantAccountId, string $connectorName): ?MerchantConnectorAccount
    {
        return MerchantConnectorAccount::where('merchant_account_id', $merchantAccountId)
            ->where('connector_name', $connectorName)
            ->first();
    }

    public function findActiveConnectorByMerchantAndName(int|string $merchantAccountId, string $connectorName): ?MerchantConnectorAccount
    {
        return MerchantConnectorAccount::where('merchant_account_id', $merchantAccountId)
            ->where('disabled', false)
            ->where('connector_name', $connectorName)
            ->first();
    }

    public function getActiveConnectorsByMerchant(int|string $merchantAccountId): Collection
    {
        return MerchantConnectorAccount::where('merchant_account_id', $merchantAccountId)
            ->where('disabled', false)
            ->get();
    }

    public function getConnectorsByMerchant(int|string $merchantAccountId): Collection
    {
        return MerchantConnectorAccount::where('merchant_account_id', $merchantAccountId)->get();
    }

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

    public function updateConnector(MerchantConnectorAccount $connector, array $attributes): MerchantConnectorAccount
    {
        $connector->update($attributes);

        return $connector;
    }

    public function findProfileByMerchant(int|string $merchantAccountId): ?BusinessProfile
    {
        return BusinessProfile::where('merchant_account_id', $merchantAccountId)->first();
    }

    public function findMerchantByKeyOrNull(string $key): ?MerchantAccount
    {
        return MerchantAccount::where('key', $key)->first();
    }

    public function findConnectorByMerchantAndKeyOrNull(int|string $merchantAccountId, string $connectorKey): ?MerchantConnectorAccount
    {
        return MerchantConnectorAccount::where('merchant_account_id', $merchantAccountId)
            ->where('key', $connectorKey)
            ->first();
    }
}
