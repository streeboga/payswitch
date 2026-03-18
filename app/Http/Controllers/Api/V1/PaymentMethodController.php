<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\JsonApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Streeboga\PaymentData\Models\Customer;
use Streeboga\PaymentData\Models\PaymentMethod;

final class PaymentMethodController extends Controller
{
    use JsonApiResponse;

    public function store(string $customerKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $customer = Customer::where('key', $customerKey)
            ->where('merchant_account_id', $merchantAccountId)
            ->firstOrFail();

        $attributes = $request->input('data.attributes', []);

        if (isset($attributes['card_number'])) {
            Log::warning('Full card number received in payment method creation — should use client-side tokenization in production');
            $cardNumber = $attributes['card_number'];
            $last4 = substr($cardNumber, -4);
            $brand = self::detectBrand($cardNumber);
            // Don't store or pass card_number further
        } else {
            $last4 = $attributes['card_last4'] ?? null;
            $brand = $attributes['card_brand'] ?? null;
        }

        $pm = PaymentMethod::create([
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
            'metadata' => $attributes['metadata'] ?? null,
        ]);

        return $this->jsonApiResource(
            model: $pm,
            type: 'payment-methods',
            attributes: $this->pmAttributes($pm),
            status: 201,
            headers: ['Location' => url("/api/v1/payment-methods/{$pm->key}")],
        );
    }

    public function index(string $customerKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $customer = Customer::where('key', $customerKey)
            ->where('merchant_account_id', $merchantAccountId)
            ->firstOrFail();

        $methods = PaymentMethod::where('customer_id', $customer->id)
            ->where('merchant_account_id', $merchantAccountId)
            ->get();

        return $this->jsonApiCollection($methods, 'payment-methods', fn ($pm) => $this->pmAttributes($pm));
    }

    public function show(string $pmKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $pm = PaymentMethod::where('key', $pmKey)
            ->where('merchant_account_id', $merchantAccountId)
            ->firstOrFail();

        return $this->jsonApiResource(
            model: $pm,
            type: 'payment-methods',
            attributes: $this->pmAttributes($pm),
        );
    }

    public function destroy(string $pmKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $pm = PaymentMethod::where('key', $pmKey)
            ->where('merchant_account_id', $merchantAccountId)
            ->firstOrFail();

        $pm->delete();

        return $this->jsonApiNoContent();
    }

    public function setDefault(string $pmKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $pm = PaymentMethod::where('key', $pmKey)
            ->where('merchant_account_id', $merchantAccountId)
            ->firstOrFail();

        // Unmark all other payment methods for this customer
        PaymentMethod::where('customer_id', $pm->customer_id)
            ->where('merchant_account_id', $merchantAccountId)
            ->where('id', '!=', $pm->id)
            ->update(['is_default' => false]);

        $pm->update(['is_default' => true]);

        return $this->jsonApiResource(
            model: $pm->fresh(),
            type: 'payment-methods',
            attributes: $this->pmAttributes($pm->fresh()),
        );
    }

    private function pmAttributes(PaymentMethod $pm): array
    {
        return [
            'type' => $pm->type,
            'card_last4' => $pm->card_last4,
            'card_brand' => $pm->card_brand,
            'card_exp_month' => $pm->card_exp_month,
            'card_exp_year' => $pm->card_exp_year,
            'card_holder_name' => $pm->card_holder_name,
            'connector_name' => $pm->connector_name,
            'is_default' => $pm->is_default,
            'metadata' => $pm->metadata,
            'created_at' => $pm->created_at->toIso8601String(),
        ];
    }

    private static function detectBrand(string $cardNumber): string
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
