<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\PaymentMethod\CreatePaymentMethodData;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\PaymentMethodRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Streeboga\PaymentData\Models\PaymentMethod;

final readonly class PaymentMethodService
{
    public function __construct(
        private CustomerRepositoryInterface $customerRepository,
        private PaymentMethodRepositoryInterface $paymentMethodRepository,
    ) {}

    public function create(CreatePaymentMethodData $dto, string $customerKey, int|string $merchantAccountId): PaymentMethod
    {
        $customer = $this->customerRepository->findByKey($customerKey, $merchantAccountId);

        $last4 = $dto->card_last4;
        $brand = $dto->card_brand;

        if ($dto->card_number) {
            Log::warning('Full card number received — should use client-side tokenization in production');
            $last4 = substr($dto->card_number, -4);
            $brand = self::detectBrand($dto->card_number);
        }

        $metadata = $dto->metadata ? array_slice($dto->metadata, 0, 50) : null;

        return $this->paymentMethodRepository->create([
            'customer_id' => $customer->id,
            'merchant_account_id' => $merchantAccountId,
            'type' => $dto->type,
            'card_last4' => $last4,
            'card_brand' => $brand,
            'card_exp_month' => $dto->card_exp_month,
            'card_exp_year' => $dto->card_exp_year,
            'card_holder_name' => $dto->card_holder_name,
            'connector_name' => $dto->connector_name,
            'connector_token' => $dto->connector_token ?? 'tok_'.bin2hex(random_bytes(16)),
            'is_default' => $dto->is_default,
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
