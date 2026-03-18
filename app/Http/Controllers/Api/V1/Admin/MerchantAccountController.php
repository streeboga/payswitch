<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Requests\Api\Admin\StoreMerchantAccountRequest;
use App\Http\Resources\MerchantAccountResource;
use App\Services\MerchantService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

#[Group(name: 'Admin > Merchant Accounts', description: 'Merchant account provisioning', weight: 11)]
final class MerchantAccountController extends Controller
{
    public function __construct(
        private readonly MerchantService $merchantService,
    ) {}

    /**
     * Create a merchant account.
     *
     * Creates a new merchant account under the specified organization.
     */
    #[Response(201, description: 'Merchant account created')]
    #[Response(422, description: 'Validation error')]
    public function store(StoreMerchantAccountRequest $request): JsonResponse
    {
        $merchant = $this->merchantService->createMerchantAccount($request->toDto());

        return (new MerchantAccountResource($merchant))
            ->withStatus(201)
            ->withHeader('Location', url("/api/v1/merchants/{$merchant->key}"))
            ->toResponse($request);
    }

    /**
     * Get a merchant account.
     *
     * Retrieves the details of a merchant account.
     */
    #[PathParameter('merchantKey', description: 'Merchant public key', example: 'merchant_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Merchant account details')]
    #[Response(404, description: 'Merchant not found')]
    public function show(string $merchantKey, Request $request): JsonResponse
    {
        $merchant = $this->merchantService->findMerchant($merchantKey);

        return (new MerchantAccountResource($merchant))->toResponse($request);
    }
}
