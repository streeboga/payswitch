<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\DataTransferObjects\Customer\CreateCustomerData;
use App\DataTransferObjects\Customer\UpdateCustomerData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\StoreDashboardCustomerRequest;
use App\Http\Requests\Dashboard\UpdateDashboardCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Services\CustomerService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

#[Group('Dashboard Customers', description: 'Customer management for the dashboard', weight: 12)]
final class DashboardCustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customerService,
    ) {}

    /**
     * List customers
     *
     * Retrieve all customers for the current merchant.
     */
    #[Response(200, description: 'Customer list')]
    public function index(Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        Gate::authorize('customer.viewAny', [$merchantId]);
        $customers = $this->customerService->list($merchantId);

        return CustomerResource::jsonApiList($customers, $request);
    }

    /**
     * Create customer
     *
     * Create a new customer for the current merchant.
     */
    #[Response(201, description: 'Customer created')]
    #[Response(422, description: 'Validation error')]
    public function store(StoreDashboardCustomerRequest $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        Gate::authorize('customer.create', [$merchantId]);

        $validated = $request->validated();

        $customer = $this->customerService->create(
            CreateCustomerData::from($validated),
            $merchantId,
        );

        return (new CustomerResource($customer))
            ->withStatus(201)
            ->withHeader('Location', "/api/v1/dashboard/customers/{$customer->key}")
            ->toResponse($request);
    }

    /**
     * Get customer
     *
     * Retrieve a single customer with details.
     */
    #[PathParameter('customerKey', description: 'Customer public key', example: 'cus_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Customer details')]
    #[Response(404, description: 'Customer not found')]
    public function show(string $customerKey, Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        Gate::authorize('customer.view', [$merchantId]);
        $customer = $this->customerService->find($customerKey, $merchantId);

        return (new CustomerResource($customer))->toResponse($request);
    }

    /**
     * Update customer
     *
     * Update customer fields. Only provided fields are changed.
     */
    #[PathParameter('customerKey', description: 'Customer public key', example: 'cus_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Customer updated')]
    #[Response(404, description: 'Customer not found')]
    #[Response(422, description: 'Validation error')]
    public function update(string $customerKey, UpdateDashboardCustomerRequest $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        Gate::authorize('customer.update', [$merchantId]);

        $validated = $request->validated();

        $customer = $this->customerService->update(
            $customerKey,
            UpdateCustomerData::from($validated),
            $merchantId,
        );

        return (new CustomerResource($customer))->toResponse($request);
    }

    /**
     * Delete customer
     *
     * Remove a customer.
     */
    #[PathParameter('customerKey', description: 'Customer public key', example: 'cus_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(204, description: 'Customer deleted')]
    #[Response(404, description: 'Customer not found')]
    public function destroy(string $customerKey, Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        Gate::authorize('customer.delete', [$merchantId]);
        $this->customerService->delete($customerKey, $merchantId);

        return response()->json(null, 204);
    }
}
