<?php

declare(strict_types=1);

namespace App\Services;

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
            $attributes['key'] = $customId;
        }

        return Customer::create($attributes);
    }

    public function list(int|string $merchantAccountId): \Illuminate\Database\Eloquent\Collection
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

        $customer->update(array_filter($data, fn ($value) => $value !== null));

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
