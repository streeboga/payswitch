<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Services\CustomerService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $customers = $this->customerService->list($merchantId);

        return CustomerResource::jsonApiList($customers, $request);
    }

    /**
     * Get customer
     *
     * Retrieve a single customer with details.
     */
    #[Response(200, description: 'Customer details')]
    #[Response(404, description: 'Customer not found')]
    public function show(string $customerKey, Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        $customer = $this->customerService->find($customerKey, $merchantId);

        return (new CustomerResource($customer))->toResponse($request);
    }
}
