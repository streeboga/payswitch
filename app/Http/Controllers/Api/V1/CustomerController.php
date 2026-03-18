<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\Customer\StoreCustomerRequest;
use App\Http\Requests\Api\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Services\CustomerService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

#[Group(name: 'Customers', weight: 2)]
final class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customerService,
    ) {}

    /**
     * List customers.
     *
     * Returns all customers belonging to the authenticated merchant.
     */
    public function index(Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $customers = $this->customerService->list($merchantAccountId);

        return CustomerResource::jsonApiList($customers, $request);
    }

    /**
     * Create a customer.
     *
     * Creates a new customer record for the authenticated merchant.
     */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $attributes = $request->validated('data.attributes') ?? [];
        $customId = $request->input('data.id');
        $merchantAccountId = $request->attributes->get('merchant_id');

        $customer = $this->customerService->create($attributes, $merchantAccountId, $customId);

        return (new CustomerResource($customer))
            ->withStatus(201)
            ->withHeader('Location', url("/api/v1/customers/{$customer->key}"))
            ->toResponse($request);
    }

    /**
     * Get a customer.
     *
     * Retrieves the details of a customer that belongs to the authenticated merchant.
     */
    public function show(string $customerKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $customer = $this->customerService->find($customerKey, $merchantAccountId);

        return (new CustomerResource($customer))->toResponse($request);
    }

    /**
     * Update a customer.
     *
     * Updates an existing customer's attributes. Only provided fields are updated.
     */
    public function update(string $customerKey, UpdateCustomerRequest $request): JsonResponse
    {
        $attributes = $request->validated('data.attributes') ?? [];
        $merchantAccountId = $request->attributes->get('merchant_id');

        $customer = $this->customerService->update($customerKey, $attributes, $merchantAccountId);

        return (new CustomerResource($customer))->toResponse($request);
    }

    /**
     * Delete a customer.
     *
     * Permanently deletes a customer and disassociates any linked payment methods.
     */
    public function destroy(string $customerKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $this->customerService->delete($customerKey, $merchantAccountId);

        return response()->json(null, 204);
    }
}
