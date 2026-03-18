<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\Admin\CreateConnectorData;
use App\DataTransferObjects\Admin\UpdateConnectorData;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
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

        return $this->merchantRepository->createConnector([
            'merchant_account_id' => $merchant->id,
            'business_profile_id' => $profileId,
            'connector_name' => $dto->connector_name,
            'connector_type' => $dto->connector_type,
            'connector_account_details' => $dto->connector_account_details,
            'payment_methods_enabled' => $dto->payment_methods_enabled,
            'test_mode' => $dto->test_mode,
        ]);
    }

    public function list(string $merchantKey): Collection
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);

        return $this->merchantRepository->getConnectorsByMerchant($merchant->id);
    }

    public function find(string $merchantKey, string $connectorKey): MerchantConnectorAccount
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);

        return $this->merchantRepository->findConnectorByMerchantAndKey($merchant->id, $connectorKey);
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

        return $connector->fresh();
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
