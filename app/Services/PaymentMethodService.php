<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\PaymentMethodRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Streeboga\PaymentData\Models\PaymentMethod;

final class PaymentMethodService
{
    public function __construct(
        private CustomerRepositoryInterface $customerRepository,
        private PaymentMethodRepositoryInterface $paymentMethodRepository,
    ) {}

    public function create(array $attributes, string $customerKey, int|string $merchantAccountId): PaymentMethod
    {
        $customer = $this->customerRepository->findByKey($customerKey, $merchantAccountId);

        if (isset($attributes['card_number'])) {
            Log::warning('Full card number received — should use client-side tokenization in production');
            $cardNumber = $attributes['card_number'];
            $last4 = substr($cardNumber, -4);
            $brand = self::detectBrand($cardNumber);
        } else {
            $last4 = $attributes['card_last4'] ?? null;
            $brand = $attributes['card_brand'] ?? null;
        }

        $metadata = isset($attributes['metadata']) && is_array($attributes['metadata'])
            ? array_slice($attributes['metadata'], 0, 50)
            : null;

        return $this->paymentMethodRepository->create([
            'customer_id' => $customer->id,
            'merchant_account_id' => $merchantAccountId,
            'type' => $attributes['type'] ?? 'card',
            'card_last4' => $last4,
            'card_brand' => $brand,
            'card_exp_month' => $attributes['card_exp_month'] ?? null,
            'card_exp_year' => $attributes['card_exp_year'] ?? null,
            'card_holder_name' => $attributes['card_holder_name'] ?? null,
            'connector_name' => $attributes['connector_name'],
            'connector_token' => $attributes['connector_token'] ?? 'tok_'.bin2hex(random_bytes(16)),
            'is_default' => $attributes['is_default'] ?? false,
            'metadata' => $metadata,
        ]);
    }

    public function listForCustomer(string $customerKey, int|string $merchantAccountId): Collection
    {
        $customer = $this->customerRepository->findByKey($customerKey, $merchantAccountId);

        return $this->paymentMethodRepository->findByCustomer($customer->id, $merchantAccountId);
    }

    public function find(string $pmKey, int|string $merchantAccountId): PaymentMethod
    {
        return $this->paymentMethodRepository->findByKey($pmKey, $merchantAccountId);
    }

    public function delete(string $pmKey, int|string $merchantAccountId): void
    {
        $pm = $this->paymentMethodRepository->findByKey($pmKey, $merchantAccountId);
        $this->paymentMethodRepository->delete($pm);
    }

    public function setDefault(string $pmKey, int|string $merchantAccountId): PaymentMethod
    {
        $pm = $this->paymentMethodRepository->findByKey($pmKey, $merchantAccountId);

        DB::transaction(function () use ($pm, $merchantAccountId) {
            $this->paymentMethodRepository->unsetDefaultForCustomer($pm->customer_id, $merchantAccountId, $pm->id);
            $this->paymentMethodRepository->update($pm, ['is_default' => true]);
        });

        return $pm->fresh();
    }

    public static function detectBrand(string $cardNumber): string
    {
        $number = preg_replace('/\D/', '', $cardNumber);

        return match (true) {
            str_starts_with($number, '4') => 'visa',
            str_starts_with($number, '5') && in_array($number[1] ?? '', ['1', '2', '3', '4', '5']) => 'mastercard',
            str_starts_with($number, '2') && isset($number[3]) && (int) substr($number, 0, 4) >= 2221 && (int) substr($number, 0, 4) <= 2720 => 'mastercard',
            str_starts_with($number, '220') => 'mir',
            str_starts_with($number, '34') || str_starts_with($number, '37') => 'amex',
            default => 'unknown',
        };
    }
}
