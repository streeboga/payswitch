<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\JsonApiResponse;
use App\Http\Requests\Api\Admin\StoreConnectorRequest;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;

#[Group(name: 'Admin > Connectors', weight: 14)]
final class ConnectorController extends Controller
{
    use JsonApiResponse;

    public function __construct(
        private MerchantRepositoryInterface $merchantRepository,
    ) {}

    /**
     * Create a connector.
     *
     * Registers a new payment connector (PSP) for the specified merchant account. The connector
     * account details contain the credentials required to communicate with the PSP. Optionally
     * associate the connector with a business profile for scoped routing.
     *
     * @pathParam merchantKey string required The unique key of the merchant account. Example: mer_1a2b3c4d5e
     */
    public function store(StoreConnectorRequest $request, string $merchantKey): JsonResponse
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
        $attrs = $request->validatedAttributes();

        $profileId = null;
        if (! empty($attrs['profile_id'])) {
            $profile = $this->merchantRepository->findProfileByKey($attrs['profile_id']);
            $profileId = $profile->id;
        }

        $connector = $this->merchantRepository->createConnector([
            'merchant_account_id' => $merchant->id,
            'business_profile_id' => $profileId,
            'connector_name' => $attrs['connector_name'],
            'connector_type' => $attrs['connector_type'],
            'connector_account_details' => $attrs['connector_account_details'],
            'payment_methods_enabled' => $attrs['payment_methods_enabled'] ?? null,
            'test_mode' => $attrs['test_mode'] ?? false,
        ]);

        return $this->jsonApiResource(
            model: $connector,
            type: 'connectors',
            attributes: $this->connectorAttributes($connector),
            status: 201,
            headers: ['Location' => url("/api/v1/merchants/{$merchantKey}/connectors/{$connector->key}")],
        );
    }

    /**
     * List connectors.
     *
     * Returns all payment connectors configured for the specified merchant account.
     * Each connector includes its type, enabled payment methods, and operational status.
     *
     * @pathParam merchantKey string required The unique key of the merchant account. Example: mer_1a2b3c4d5e
     */
    public function index(string $merchantKey): JsonResponse
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);

        $connectors = $this->merchantRepository->getConnectorsByMerchant($merchant->id);

        return $this->jsonApiCollection(
            models: $connectors,
            type: 'connectors',
            attributeMapper: fn (MerchantConnectorAccount $connector) => $this->connectorAttributes($connector),
        );
    }

    /**
     * Get a connector.
     *
     * Retrieves the details of a specific connector including its configuration,
     * enabled payment methods, and whether it is in test mode or disabled.
     *
     * @pathParam merchantKey string required The unique key of the merchant account. Example: mer_1a2b3c4d5e
     * @pathParam connectorKey string required The unique key of the connector. Example: mca_1a2b3c4d5e
     */
    public function show(string $merchantKey, string $connectorKey): JsonResponse
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);

        $connector = $this->merchantRepository->findConnectorByMerchantAndKey($merchant->id, $connectorKey);

        return $this->jsonApiResource(
            model: $connector,
            type: 'connectors',
            attributes: $this->connectorAttributes($connector),
        );
    }

    /**
     * Update a connector.
     *
     * Updates an existing connector's configuration such as credentials, enabled payment methods,
     * test mode, or disabled status. Only the provided fields are updated; omitted fields remain unchanged.
     *
     * @pathParam merchantKey string required The unique key of the merchant account. Example: mer_1a2b3c4d5e
     * @pathParam connectorKey string required The unique key of the connector. Example: mca_1a2b3c4d5e
     */
    public function update(Request $request, string $merchantKey, string $connectorKey): JsonResponse
    {
        $request->validate([
            'data.attributes.connector_name' => ['sometimes', 'string'],
            'data.attributes.disabled' => ['sometimes', 'boolean'],
            'data.attributes.test_mode' => ['sometimes', 'boolean'],
            'data.attributes.connector_account_details' => ['sometimes', 'array'],
            'data.attributes.payment_methods_enabled' => ['sometimes', 'array'],
        ]);

        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);

        $connector = $this->merchantRepository->findConnectorByMerchantAndKey($merchant->id, $connectorKey);

        $attrs = $request->input('data.attributes', []);

        $updateData = collect($attrs)->only([
            'connector_name',
            'connector_type',
            'payment_methods_enabled',
            'test_mode',
            'disabled',
        ])->toArray();

        if (isset($attrs['connector_account_details'])) {
            $updateData['connector_account_details'] = $attrs['connector_account_details'];
        }

        if (isset($attrs['profile_id'])) {
            $profile = $this->merchantRepository->findProfileByKey($attrs['profile_id']);
            $updateData['business_profile_id'] = $profile->id;
        }

        $this->merchantRepository->updateConnector($connector, $updateData);
        $connector->refresh();

        return $this->jsonApiResource(
            model: $connector,
            type: 'connectors',
            attributes: $this->connectorAttributes($connector),
        );
    }

    /**
     * Delete a connector.
     *
     * Permanently removes a connector from the merchant account. Active payment intents
     * using this connector will not be affected, but no new payments can be routed to it.
     *
     * @pathParam merchantKey string required The unique key of the merchant account. Example: mer_1a2b3c4d5e
     * @pathParam connectorKey string required The unique key of the connector. Example: mca_1a2b3c4d5e
     */
    public function destroy(string $merchantKey, string $connectorKey): JsonResponse
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);

        $connector = $this->merchantRepository->findConnectorByMerchantAndKey($merchant->id, $connectorKey);

        $this->merchantRepository->deleteConnector($connector);

        return $this->jsonApiNoContent();
    }

    private function connectorAttributes(MerchantConnectorAccount $connector): array
    {
        return [
            'connector_name' => $connector->connector_name,
            'connector_type' => $connector->connector_type,
            'payment_methods_enabled' => $connector->payment_methods_enabled,
            'test_mode' => $connector->test_mode,
            'disabled' => $connector->disabled,
            'created_at' => $connector->created_at->toIso8601String(),
        ];
    }
}
