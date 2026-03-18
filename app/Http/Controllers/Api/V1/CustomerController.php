<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\JsonApiResponse;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Streeboga\PaymentData\Models\Customer;

final class CustomerController extends Controller
{
    use JsonApiResponse;

    public function __construct(
        private readonly CustomerService $customerService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $customers = $this->customerService->list($merchantAccountId);

        return $this->jsonApiCollection($customers, 'customers', fn ($customer) => $this->customerAttributes($customer));
    }

    public function store(Request $request): JsonResponse
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

    public function update(string $customerKey, Request $request): JsonResponse
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
