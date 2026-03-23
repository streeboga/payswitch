<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\Admin\CreateConnectorData;
use App\DataTransferObjects\Admin\UpdateConnectorData;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentConnectors\ConnectorFactory;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;

final readonly class ConnectorService
{
    public function __construct(
        private MerchantRepositoryInterface $merchantRepository,
    ) {}

    public function create(string $merchantKey, CreateConnectorData $dto): MerchantConnectorAccount
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);

        $profileId = $this->resolveProfileId($dto->profile_id)
            ?? $this->merchantRepository->findProfileByMerchant($merchant->id)?->id;

        $connector = $this->merchantRepository->createConnector([
            'merchant_account_id' => $merchant->id,
            'business_profile_id' => $profileId,
            'connector_name' => $dto->connector_name,
            'connector_type' => $dto->connector_type,
            'connector_account_details' => $dto->connector_account_details,
            'payment_methods_enabled' => $dto->payment_methods_enabled,
            'test_mode' => $dto->test_mode,
        ]);

        $connector->load('merchantAccount');

        return $connector;
    }

    /**
     * @return Collection<int, MerchantConnectorAccount>
     */
    public function list(string $merchantKey): Collection
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);

        return $this->merchantRepository->getConnectorsByMerchant($merchant->id)
            ->load('merchantAccount');
    }

    public function find(string $merchantKey, string $connectorKey): MerchantConnectorAccount
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
        $connector = $this->merchantRepository->findConnectorByMerchantAndKey($merchant->id, $connectorKey);
        $connector->load('merchantAccount');

        return $connector;
    }

    public function update(string $merchantKey, string $connectorKey, UpdateConnectorData $dto): MerchantConnectorAccount
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
        $connector = $this->merchantRepository->findConnectorByMerchantAndKey($merchant->id, $connectorKey);

        $updateData = $dto->toUpdateArray();

        if (array_key_exists('profile_id', $updateData)) {
            $updateData['business_profile_id'] = $this->resolveProfileId($updateData['profile_id']);
            unset($updateData['profile_id']);
        }

        $this->merchantRepository->updateConnector($connector, $updateData);

        $connector->refresh();

        return $connector;
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function testConnection(string $merchantKey, string $connectorKey): array
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
        $connector = $this->merchantRepository->findConnectorByMerchantAndKey($merchant->id, $connectorKey);
        $driver = ConnectorFactory::resolve($connector);

        return $driver->testConnection();
    }

    /**
     * @return array<string, mixed>
     */
    public function getCapabilities(string $merchantKey, string $connectorKey): array
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
        $connector = $this->merchantRepository->findConnectorByMerchantAndKey($merchant->id, $connectorKey);

        $driverClass = ConnectorFactory::resolveClass($connector->connector_name);

        if (! $driverClass) {
            return [];
        }

        $capabilities = $driverClass::capabilities();

        return [
            'display_name' => $capabilities->defaultDisplayName,
            'logo_path' => $capabilities->logoPath,
            'direct_methods' => array_keys($capabilities->directMethods),
            'fallback_session_type' => $capabilities->fallbackSessionType->value,
            'amount_unit' => $capabilities->amountUnit->value,
        ];
    }

    public function delete(string $merchantKey, string $connectorKey): void
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
        $connector = $this->merchantRepository->findConnectorByMerchantAndKey($merchant->id, $connectorKey);
        $this->merchantRepository->deleteConnector($connector);
    }

    private function resolveProfileId(?string $profileKey): ?int
    {
        if (! $profileKey) {
            return null;
        }

        return $this->merchantRepository->findProfileByKey($profileKey)->id;
    }
}
