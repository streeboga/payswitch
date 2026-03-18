<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Contracts\MerchantRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;

final class ConnectorService
{
    public function __construct(
        private MerchantRepositoryInterface $merchantRepository,
    ) {}

    public function create(string $merchantKey, array $data): MerchantConnectorAccount
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);

        $profileId = null;
        if (! empty($data['profile_id'])) {
            $profile = $this->merchantRepository->findProfileByKey($data['profile_id']);
            $profileId = $profile->id;
        }

        return $this->merchantRepository->createConnector([
            'merchant_account_id' => $merchant->id,
            'business_profile_id' => $profileId,
            'connector_name' => $data['connector_name'],
            'connector_type' => $data['connector_type'],
            'connector_account_details' => $data['connector_account_details'],
            'payment_methods_enabled' => $data['payment_methods_enabled'] ?? null,
            'test_mode' => $data['test_mode'] ?? false,
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

    public function update(string $merchantKey, string $connectorKey, array $data): MerchantConnectorAccount
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
        $connector = $this->merchantRepository->findConnectorByMerchantAndKey($merchant->id, $connectorKey);

        $updateData = collect($data)->only([
            'connector_name', 'connector_type', 'payment_methods_enabled',
            'test_mode', 'disabled',
        ])->toArray();

        if (isset($data['connector_account_details'])) {
            $updateData['connector_account_details'] = $data['connector_account_details'];
        }

        if (isset($data['profile_id'])) {
            $profile = $this->merchantRepository->findProfileByKey($data['profile_id']);
            $updateData['business_profile_id'] = $profile->id;
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
}
