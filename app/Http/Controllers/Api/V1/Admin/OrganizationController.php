<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\JsonApiResponse;
use App\Http\Requests\Api\Admin\StoreOrganizationRequest;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

#[Group(name: 'Admin > Organizations', weight: 10)]
final class OrganizationController extends Controller
{
    use JsonApiResponse;

    public function __construct(
        private MerchantRepositoryInterface $merchantRepository,
    ) {}

    /**
     * Create an organization.
     *
     * Creates a new top-level organization entity. Organizations are the highest level
     * in the merchant hierarchy and can contain multiple merchant accounts.
     */
    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $attrs = $request->validatedAttributes();

        $organization = $this->merchantRepository->createOrganization([
            'name' => $attrs['name'],
        ]);

        return $this->jsonApiResource(
            model: $organization,
            type: 'organizations',
            attributes: [
                'name' => $organization->name,
                'created_at' => $organization->created_at->toIso8601String(),
            ],
            status: 201,
            headers: ['Location' => $request->url().'/'.$organization->key],
        );
    }
}
