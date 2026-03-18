<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\JsonApiResponse;
use App\Http\Requests\Api\Customer\StoreCustomerRequest;
use App\Http\Requests\Api\Customer\UpdateCustomerRequest;
use App\Services\CustomerService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Streeboga\PaymentData\Models\Customer;

#[Group(name: 'Customers', weight: 2)]
final class CustomerController extends Controller
{
    use JsonApiResponse;

    public function __construct(
        private readonly CustomerService $customerService,
    ) {}

    /**
     * List customers.
     *
     * Returns all customers belonging to the authenticated merchant.
     * Results include customer details and their default payment method references.
     */
    public function index(Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $customers = $this->customerService->list($merchantAccountId);

        return $this->jsonApiCollection($customers, 'customers', fn ($customer) => $this->customerAttributes($customer));
    }

    /**
     * Create a customer.
     *
     * Creates a new customer record for the authenticated merchant. Customers can be
     * associated with payment intents and payment methods to enable returning customer flows.
     * An optional custom ID can be provided to link the customer to an external system.
     */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $attributes = $request->input('data.attributes', []);
        $customId = $request->input('data.id');
        $merchantAccountId = $request->attributes->get('merchant_id');

        $customer = $this->customerService->create($attributes, $merchantAccountId, $customId);

        return $this->jsonApiResource(
            model: $customer,
            type: 'customers',
            attributes: $this->customerAttributes($customer),
            status: 201,
            headers: ['Location' => url("/api/v1/customers/{$customer->key}")],
        );
    }

    /**
     * Get a customer.
     *
     * Retrieves the details of a customer that belongs to the authenticated merchant.
     *
     * @pathParam customerKey string required The unique key of the customer. Example: cus_1a2b3c4d5e
     */
    public function show(string $customerKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $customer = $this->customerService->find($customerKey, $merchantAccountId);

        return $this->jsonApiResource(
            model: $customer,
            type: 'customers',
            attributes: $this->customerAttributes($customer),
        );
    }

    /**
     * Update a customer.
     *
     * Updates an existing customer's attributes such as name, email, phone, or metadata.
     * Only the provided fields are updated; omitted fields remain unchanged.
     *
     * @pathParam customerKey string required The unique key of the customer. Example: cus_1a2b3c4d5e
     */
    public function update(string $customerKey, UpdateCustomerRequest $request): JsonResponse
    {
        $attributes = $request->input('data.attributes', []);
        $merchantAccountId = $request->attributes->get('merchant_id');

        $customer = $this->customerService->update($customerKey, $attributes, $merchantAccountId);

        return $this->jsonApiResource(
            model: $customer,
            type: 'customers',
            attributes: $this->customerAttributes($customer),
        );
    }

    /**
     * Delete a customer.
     *
     * Permanently deletes a customer and disassociates any linked payment methods.
     * This action cannot be undone.
     *
     * @pathParam customerKey string required The unique key of the customer. Example: cus_1a2b3c4d5e
     */
    public function destroy(string $customerKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $this->customerService->delete($customerKey, $merchantAccountId);

        return $this->jsonApiNoContent();
    }

    private function customerAttributes(Customer $customer): array
    {
        return [
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'phone_country_code' => $customer->phone_country_code,
            'description' => $customer->description,
            'metadata' => $customer->metadata,
            'default_payment_method_id' => $customer->default_payment_method_id,
            'created_at' => $customer->created_at->toIso8601String(),
        ];
    }
}
