<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Contracts\CustomerRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Exceptions\PaymentException;
use Streeboga\PaymentData\Models\Customer;

final class CustomerService
{
    public function __construct(
        private CustomerRepositoryInterface $customerRepository,
    ) {}

    public function create(array $data, int|string $merchantAccountId, ?string $customId = null): Customer
    {
        $attributes = [
            'merchant_account_id' => $merchantAccountId,
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'phone_country_code' => $data['phone_country_code'] ?? null,
            'description' => $data['description'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ];

        if ($customId !== null) {
            if (strlen($customId) > 64 || strlen($customId) < 1) {
                throw new PaymentException('Customer ID must be 1-64 characters', 'invalid_customer_id', 'invalid_request_error', 400);
            }
            if ($this->customerRepository->existsByKey($customId, $merchantAccountId)) {
                throw new PaymentException('Customer ID already exists', 'duplicate_customer_id', 'invalid_request_error', 409);
            }
            $attributes['key'] = $customId;
        }

        return $this->customerRepository->create($attributes);
    }

    public function list(int|string $merchantAccountId): Collection
    {
        return $this->customerRepository->list($merchantAccountId);
    }

    public function find(string $customerKey, int|string $merchantAccountId): Customer
    {
        return $this->customerRepository->findByKey($customerKey, $merchantAccountId);
    }

    public function update(string $customerKey, array $data, int|string $merchantAccountId): Customer
    {
        $customer = $this->customerRepository->findByKey($customerKey, $merchantAccountId);

        // Only update keys that were explicitly provided (including null values)
        $updateData = [];
        $allowedFields = ['name', 'email', 'phone', 'phone_country_code', 'description', 'metadata'];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }
        if (! empty($updateData)) {
            $this->customerRepository->update($customer, $updateData);
        }

        return $customer->fresh();
    }

    public function delete(string $customerKey, int|string $merchantAccountId): void
    {
        $customer = $this->customerRepository->findByKey($customerKey, $merchantAccountId);

        $this->customerRepository->delete($customer);
    }
}
