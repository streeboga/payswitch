<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\JsonApiResponse;
use App\Http\Requests\Api\Admin\StoreMerchantAccountRequest;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

#[Group(name: 'Admin > Merchant Accounts', weight: 11)]
final class MerchantAccountController extends Controller
{
    use JsonApiResponse;

    public function __construct(
        private MerchantRepositoryInterface $merchantRepository,
    ) {}

    /**
     * Create a merchant account.
     *
     * Creates a new merchant account under the specified organization. Each merchant account
     * receives a unique publishable key and can have its own connectors, business profiles,
     * and API keys.
     */
    public function store(StoreMerchantAccountRequest $request): JsonResponse
    {
        $attrs = $request->validatedAttributes();

        $organization = $this->merchantRepository->findOrganizationByKey($attrs['organization_id']);

        $merchant = $this->merchantRepository->createMerchantAccount([
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

    /**
     * Get a merchant account.
     *
     * Retrieves the details of a merchant account including its name, publishable key,
     * and parent organization reference.
     *
     * @pathParam merchantKey string required The unique key of the merchant account. Example: mer_1a2b3c4d5e
     */
    public function show(string $merchantKey): JsonResponse
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);

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
