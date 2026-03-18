<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;

interface MerchantRepositoryInterface
{
    public function createOrganization(array $attributes): Organization;

    public function createMerchantAccount(array $attributes): MerchantAccount;

    public function createBusinessProfile(array $attributes): BusinessProfile;

    public function createApiKey(array $attributes): ApiKey;

    public function createConnector(array $attributes): MerchantConnectorAccount;

    public function findOrganizationByKey(string $key): Organization;

    public function findMerchantByKey(string $key): MerchantAccount;

    public function findProfileByKey(string $key): BusinessProfile;

    public function findConnectorByKey(string $key): MerchantConnectorAccount;

    public function findConnectorByMerchantAndKey(int|string $merchantAccountId, string $connectorKey): MerchantConnectorAccount;

    public function findConnectorByMerchantAndName(int|string $merchantAccountId, string $connectorName): ?MerchantConnectorAccount;

    public function findActiveConnectorByMerchantAndName(int|string $merchantAccountId, string $connectorName): ?MerchantConnectorAccount;

    public function getActiveConnectorsByMerchant(int|string $merchantAccountId): Collection;

    public function getConnectorsByMerchant(int|string $merchantAccountId): Collection;

    public function getFirstActiveConnector(int|string $merchantAccountId, array $excludeConnectors = []): ?MerchantConnectorAccount;

    public function deleteConnector(MerchantConnectorAccount $connector): void;

    public function updateConnector(MerchantConnectorAccount $connector, array $attributes): MerchantConnectorAccount;

    public function findProfileByMerchant(int|string $merchantAccountId): ?BusinessProfile;

    public function findMerchantByKeyOrNull(string $key): ?MerchantAccount;

    public function findConnectorByMerchantAndKeyOrNull(int|string $merchantAccountId, string $connectorKey): ?MerchantConnectorAccount;

    /**
     * @return Collection<int, Organization>
     */
    public function listOrganizations(): Collection;

    /**
     * @return Collection<int, MerchantAccount>
     */
    public function listAllMerchants(): Collection;

    /**
     * @return Collection<int, ApiKey>
     */
    public function listApiKeysByMerchant(int|string $merchantAccountId): Collection;

    public function revokeApiKey(int|string $merchantAccountId, string $apiKeyKey): void;

    /**
     * @return Collection<int, BusinessProfile>
     */
    public function listProfilesByMerchant(int|string $merchantAccountId): Collection;

    public function updateProfile(BusinessProfile $profile, array $attributes): BusinessProfile;
}
