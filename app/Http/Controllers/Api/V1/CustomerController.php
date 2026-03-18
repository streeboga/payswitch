<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\Customer\StoreCustomerRequest;
use App\Http\Requests\Api\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Services\CustomerService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

#[Group(name: 'Customers', description: 'Customer CRUD operations', weight: 2)]
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
    #[Response(200, description: 'Customer list')]
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
    #[Response(201, description: 'Customer created')]
    #[Response(422, description: 'Validation error')]
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');

        $customer = $this->customerService->create($request->toDto(), $merchantAccountId, $request->customId());

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
    #[PathParameter('customerKey', description: 'Customer public key', example: 'cus_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Customer details')]
    #[Response(404, description: 'Customer not found')]
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
    #[PathParameter('customerKey', description: 'Customer public key', example: 'cus_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Customer updated')]
    #[Response(404, description: 'Customer not found')]
    public function update(string $customerKey, UpdateCustomerRequest $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');

        $customer = $this->customerService->update($customerKey, $request->toDto(), $merchantAccountId);

        return (new CustomerResource($customer))->toResponse($request);
    }

    /**
     * Delete a customer.
     *
     * Permanently deletes a customer and disassociates any linked payment methods.
     */
    #[PathParameter('customerKey', description: 'Customer public key', example: 'cus_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(204, description: 'Customer deleted')]
    #[Response(404, description: 'Customer not found')]
    public function destroy(string $customerKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $this->customerService->delete($customerKey, $merchantAccountId);

        return response()->json(null, 204);
    }
}
