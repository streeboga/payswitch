<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\JsonApiResponse;
use App\Http\Requests\Api\Admin\StoreOrganizationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Streeboga\PaymentData\Models\Organization;

final class OrganizationController extends Controller
{
    use JsonApiResponse;

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $attrs = $request->validatedAttributes();

        $organization = Organization::create([
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
