<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\JsonApiResponse;
use App\Http\Requests\Api\Admin\StoreMerchantAccountRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;

final class MerchantAccountController extends Controller
{
    use JsonApiResponse;

    public function store(StoreMerchantAccountRequest $request): JsonResponse
    {
        $attrs = $request->validatedAttributes();

        $organization = Organization::where('key', $attrs['organization_id'])->firstOrFail();

        $merchant = MerchantAccount::create([
            'org_id' => $organization->id,
            'name' => $attrs['name'],
        ]);

        return $this->jsonApiResource(
            model: $merchant,
            type: 'merchants',
            attributes: [
                'name' => $merchant->name,
                'publishable_key' => $merchant->publishable_key,
                'organization_id' => $organization->key,
                'created_at' => $merchant->created_at->toIso8601String(),
            ],
            status: 201,
            headers: ['Location' => url("/api/v1/merchants/{$merchant->key}")],
        );
    }

    public function show(string $merchantKey): JsonResponse
    {
        $merchant = MerchantAccount::where('key', $merchantKey)->firstOrFail();

        return $this->jsonApiResource(
            model: $merchant,
            type: 'merchants',
            attributes: [
                'name' => $merchant->name,
                'publishable_key' => $merchant->publishable_key,
                'organization_id' => $merchant->organization->key,
                'created_at' => $merchant->created_at->toIso8601String(),
            ],
        );
    }
}
