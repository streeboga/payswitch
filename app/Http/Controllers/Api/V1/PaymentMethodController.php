<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\JsonApiResponse;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\PaymentMethodRepositoryInterface;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Streeboga\PaymentData\Models\PaymentMethod;

#[Group(name: 'Payment Methods', weight: 4)]
final class PaymentMethodController extends Controller
{
    use JsonApiResponse;

    public function __construct(
        private CustomerRepositoryInterface $customerRepository,
        private PaymentMethodRepositoryInterface $paymentMethodRepository,
    ) {}

    /**
     * Create a payment method.
     *
     * Attaches a new payment method to an existing customer. The payment method is tokenized
     * through the specified connector. In production, card data should be tokenized client-side
     * and only the token passed to this endpoint.
     *
     * @pathParam customerKey string required The unique key of the customer. Example: cus_1a2b3c4d5e
     */
    public function store(string $customerKey, Request $request): JsonResponse
    {
        $request->validate([
            'data.attributes.type' => ['required', 'string', 'in:card,bank_account'],
            'data.attributes.connector_name' => ['required', 'string'],
        ]);

        $merchantAccountId = $request->attributes->get('merchant_id');
        $customer = $this->customerRepository->findByKey($customerKey, $merchantAccountId);

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

        $metadata = isset($attributes['metadata']) && is_array($attributes['metadata'])
            ? array_slice($attributes['metadata'], 0, 50) // limit to 50 keys
            : null;

        $pm = $this->paymentMethodRepository->create([
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

        return $this->jsonApiResource(
            model: $pm,
            type: 'payment-methods',
            attributes: $this->pmAttributes($pm),
            status: 201,
            headers: ['Location' => url("/api/v1/payment-methods/{$pm->key}")],
        );
    }

    /**
     * List payment methods.
     *
     * Returns all payment methods belonging to a specific customer under the authenticated merchant.
     * Includes card details (masked), type, and default status for each method.
     *
     * @pathParam customerKey string required The unique key of the customer. Example: cus_1a2b3c4d5e
     */
    public function index(string $customerKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $customer = $this->customerRepository->findByKey($customerKey, $merchantAccountId);

        $methods = $this->paymentMethodRepository->findByCustomer($customer->id, $merchantAccountId);

        return $this->jsonApiCollection($methods, 'payment-methods', fn ($pm) => $this->pmAttributes($pm));
    }

    /**
     * Get a payment method.
     *
     * Retrieves the details of a specific payment method including card brand,
     * last four digits, expiration, and connector information.
     *
     * @pathParam pmKey string required The unique key of the payment method. Example: pm_1a2b3c4d5e
     */
    public function show(string $pmKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $pm = $this->paymentMethodRepository->findByKey($pmKey, $merchantAccountId);

        return $this->jsonApiResource(
            model: $pm,
            type: 'payment-methods',
            attributes: $this->pmAttributes($pm),
        );
    }

    /**
     * Delete a payment method.
     *
     * Permanently removes a payment method from the customer. If the deleted method
     * was the default, no new default is automatically assigned.
     *
     * @pathParam pmKey string required The unique key of the payment method. Example: pm_1a2b3c4d5e
     */
    public function destroy(string $pmKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $pm = $this->paymentMethodRepository->findByKey($pmKey, $merchantAccountId);

        $this->paymentMethodRepository->delete($pm);

        return $this->jsonApiNoContent();
    }

    /**
     * Set default payment method.
     *
     * Marks the specified payment method as the default for its customer. Any previously
     * default payment method for the same customer is automatically unset. This operation
     * is performed within a database transaction.
     *
     * @pathParam pmKey string required The unique key of the payment method. Example: pm_1a2b3c4d5e
     */
    public function setDefault(string $pmKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $pm = $this->paymentMethodRepository->findByKey($pmKey, $merchantAccountId);

        DB::transaction(function () use ($pm, $merchantAccountId) {
            // Unmark all other payment methods for this customer
            $this->paymentMethodRepository->unsetDefaultForCustomer($pm->customer_id, $merchantAccountId, $pm->id);

            $this->paymentMethodRepository->update($pm, ['is_default' => true]);
        });

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
