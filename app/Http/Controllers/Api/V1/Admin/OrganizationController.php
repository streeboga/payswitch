<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Requests\Api\Admin\StoreOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Services\MerchantService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

#[Group(name: 'Admin > Organizations', description: 'Organization management', weight: 10)]
final class OrganizationController extends Controller
{
    public function __construct(
        private readonly MerchantService $merchantService,
    ) {}

    /**
     * Create an organization.
     *
     * Creates a new top-level organization entity.
     */
    #[Response(201, description: 'Organization created')]
    #[Response(422, description: 'Validation error')]
    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $organization = $this->merchantService->createOrganization($request->toDto());

        return (new OrganizationResource($organization))
            ->withStatus(201)
            ->withHeader('Location', $request->url().'/'.$organization->key)
            ->toResponse($request);
    }
}
