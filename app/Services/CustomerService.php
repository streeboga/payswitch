<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Exceptions\PaymentException;
use Streeboga\PaymentData\Models\Customer;

final class CustomerService
{
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
            if (Customer::where('key', $customId)->where('merchant_account_id', $merchantAccountId)->exists()) {
                throw new PaymentException('Customer ID already exists', 'duplicate_customer_id', 'invalid_request_error', 409);
            }
            $attributes['key'] = $customId;
        }

        return Customer::create($attributes);
    }

    public function list(int|string $merchantAccountId): Collection
    {
        return Customer::where('merchant_account_id', $merchantAccountId)->get();
    }

    public function find(string $customerKey, int|string $merchantAccountId): Customer
    {
        return Customer::where('key', $customerKey)
            ->where('merchant_account_id', $merchantAccountId)
            ->firstOrFail();
    }

    public function update(string $customerKey, array $data, int|string $merchantAccountId): Customer
    {
        $customer = Customer::where('key', $customerKey)
            ->where('merchant_account_id', $merchantAccountId)
            ->firstOrFail();

        // Only update keys that were explicitly provided (including null values)
        $updateData = [];
        $allowedFields = ['name', 'email', 'phone', 'phone_country_code', 'description', 'metadata'];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }
        if (! empty($updateData)) {
            $customer->update($updateData);
        }

        return $customer->fresh();
    }

    public function delete(string $customerKey, int|string $merchantAccountId): void
    {
        $customer = Customer::where('key', $customerKey)
            ->where('merchant_account_id', $merchantAccountId)
            ->firstOrFail();

        $customer->delete();
    }
}
