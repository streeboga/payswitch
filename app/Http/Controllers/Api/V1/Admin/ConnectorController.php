<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\JsonApiResponse;
use App\Http\Requests\Api\Admin\StoreConnectorRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;

final class ConnectorController extends Controller
{
    use JsonApiResponse;

    public function store(StoreConnectorRequest $request, string $merchantKey): JsonResponse
    {
        $merchant = MerchantAccount::where('key', $merchantKey)->firstOrFail();
        $attrs = $request->validatedAttributes();

        $profileId = null;
        if (! empty($attrs['profile_id'])) {
            $profile = BusinessProfile::where('key', $attrs['profile_id'])->firstOrFail();
            $profileId = $profile->id;
        }

        $connector = MerchantConnectorAccount::create([
            'merchant_account_id' => $merchant->id,
            'business_profile_id' => $profileId,
            'connector_name' => $attrs['connector_name'],
            'connector_type' => $attrs['connector_type'],
            'connector_account_details' => json_encode($attrs['connector_account_details']),
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

    public function index(string $merchantKey): JsonResponse
    {
        $merchant = MerchantAccount::where('key', $merchantKey)->firstOrFail();

        $connectors = MerchantConnectorAccount::where('merchant_account_id', $merchant->id)->get();

        return $this->jsonApiCollection(
            models: $connectors,
            type: 'connectors',
            attributeMapper: fn (MerchantConnectorAccount $connector) => $this->connectorAttributes($connector),
        );
    }

    public function show(string $merchantKey, string $connectorKey): JsonResponse
    {
        $merchant = MerchantAccount::where('key', $merchantKey)->firstOrFail();

        $connector = MerchantConnectorAccount::where('merchant_account_id', $merchant->id)
            ->where('key', $connectorKey)
            ->firstOrFail();

        return $this->jsonApiResource(
            model: $connector,
            type: 'connectors',
            attributes: $this->connectorAttributes($connector),
        );
    }

    public function update(Request $request, string $merchantKey, string $connectorKey): JsonResponse
    {
        $request->validate([
            'data.attributes.connector_name' => ['sometimes', 'string'],
            'data.attributes.disabled' => ['sometimes', 'boolean'],
            'data.attributes.test_mode' => ['sometimes', 'boolean'],
            'data.attributes.connector_account_details' => ['sometimes', 'array'],
            'data.attributes.payment_methods_enabled' => ['sometimes', 'array'],
        ]);

        $merchant = MerchantAccount::where('key', $merchantKey)->firstOrFail();

        $connector = MerchantConnectorAccount::where('merchant_account_id', $merchant->id)
            ->where('key', $connectorKey)
            ->firstOrFail();

        $attrs = $request->input('data.attributes', []);

        $updateData = collect($attrs)->only([
            'connector_name',
            'connector_type',
            'payment_methods_enabled',
            'test_mode',
            'disabled',
        ])->toArray();

        if (isset($attrs['connector_account_details'])) {
            $updateData['connector_account_details'] = json_encode($attrs['connector_account_details']);
        }

        if (isset($attrs['profile_id'])) {
            $profile = BusinessProfile::where('key', $attrs['profile_id'])->firstOrFail();
            $updateData['business_profile_id'] = $profile->id;
        }

        $connector->update($updateData);
        $connector->refresh();

        return $this->jsonApiResource(
            model: $connector,
            type: 'connectors',
            attributes: $this->connectorAttributes($connector),
        );
    }

    public function destroy(string $merchantKey, string $connectorKey): JsonResponse
    {
        $merchant = MerchantAccount::where('key', $merchantKey)->firstOrFail();

        $connector = MerchantConnectorAccount::where('merchant_account_id', $merchant->id)
            ->where('key', $connectorKey)
            ->firstOrFail();

        $connector->delete();

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
