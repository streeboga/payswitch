<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\JsonApiResponse;
use App\Http\Requests\Api\Payment\CapturePaymentRequest;
use App\Http\Requests\Api\Payment\ConfirmPaymentRequest;
use App\Http\Requests\Api\Payment\StorePaymentRequest;
use App\Services\PaymentService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Streeboga\PaymentData\Models\PaymentIntent;

#[Group(name: 'Payments', weight: 1)]
final class PaymentController extends Controller
{
    use JsonApiResponse;

    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    /**
     * Create a payment intent.
     *
     * Creates a new payment intent for the authenticated merchant. The payment can optionally
     * be confirmed immediately by setting the confirm flag to true and providing payment method data.
     * When capture_method is set to manual, the payment will require a separate capture step after confirmation.
     */
    public function store(StorePaymentRequest $request): JsonResponse
    {
        $attributes = $request->input('data.attributes', []);
        $merchantAccountId = $request->attributes->get('merchant_id');

        $payment = $this->paymentService->create($attributes, $merchantAccountId);

        if ($request->boolean('data.attributes.confirm')) {
            $payment = $this->paymentService->confirm($payment->key, $attributes, $merchantAccountId);
        }

        return $this->jsonApiResource(
            model: $payment,
            type: 'payments',
            attributes: $this->paymentAttributes($payment),
            status: 201,
            headers: ['Location' => url("/api/v1/payments/{$payment->key}")],
        );
    }

    /**
     * Get a payment intent.
     *
     * Retrieves the details of a payment intent that belongs to the authenticated merchant.
     * Returns the current status, amounts, and all associated metadata.
     *
     * @pathParam paymentKey string required The unique key of the payment intent. Example: pay_1a2b3c4d5e
     */
    public function show(string $paymentKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $payment = $this->paymentService->find($paymentKey, $merchantAccountId);

        return $this->jsonApiResource(
            model: $payment,
            type: 'payments',
            attributes: $this->paymentAttributes($payment),
        );
    }

    /**
     * Confirm a payment intent.
     *
     * Confirms a payment intent that is in the requires_confirmation status. The payment method
     * and payment method data must be provided to proceed with processing through the configured connector.
     *
     * @pathParam paymentKey string required The unique key of the payment intent. Example: pay_1a2b3c4d5e
     */
    public function confirm(string $paymentKey, ConfirmPaymentRequest $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $attributes = $request->input('data.attributes', []);

        $payment = $this->paymentService->confirm($paymentKey, $attributes, $merchantAccountId);

        return $this->jsonApiResource(
            model: $payment,
            type: 'payments',
            attributes: $this->paymentAttributes($payment),
        );
    }

    /**
     * Capture a payment intent.
     *
     * Captures a previously authorized payment intent. Only applicable to payments created
     * with capture_method set to manual. Allows partial capture by specifying an amount
     * less than the original authorization.
     *
     * @pathParam paymentKey string required The unique key of the payment intent. Example: pay_1a2b3c4d5e
     */
    public function capture(string $paymentKey, CapturePaymentRequest $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $amount = (int) $request->input('data.attributes.amount_to_capture');

        $payment = $this->paymentService->capture($paymentKey, $amount, $merchantAccountId);

        return $this->jsonApiResource(
            model: $payment,
            type: 'payments',
            attributes: $this->paymentAttributes($payment),
        );
    }

    /**
     * Cancel a payment intent.
     *
     * Cancels a payment intent that has not yet been captured or completed. Once cancelled,
     * the payment cannot be confirmed or captured. Any held funds will be released.
     *
     * @pathParam paymentKey string required The unique key of the payment intent. Example: pay_1a2b3c4d5e
     */
    public function cancel(string $paymentKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $payment = $this->paymentService->cancel($paymentKey, $merchantAccountId);

        return $this->jsonApiResource(
            model: $payment,
            type: 'payments',
            attributes: $this->paymentAttributes($payment),
        );
    }

    private function paymentAttributes(PaymentIntent $payment): array
    {
        return [
            'status' => $payment->status->value,
            'amount' => $payment->amount,
            'net_amount' => $payment->net_amount,
            'amount_capturable' => $payment->amount_capturable,
            'amount_received' => $payment->amount_received,
            'currency' => $payment->currency,
            'client_secret' => $payment->client_secret,
            'capture_method' => $payment->capture_method->value,
            'authentication_type' => $payment->authentication_type->value,
            'customer_id' => $payment->customer_id,
            'description' => $payment->description,
            'return_url' => $payment->return_url,
            'metadata' => $payment->metadata,
            'connector' => $payment->connector,
            'attempt_count' => $payment->attempt_count,
            'error_code' => $payment->error_code,
            'error_message' => $payment->error_message,
            'cancellation_reason' => $payment->cancellation_reason,
            'session_expiry' => $payment->session_expiry,
            'created_at' => $payment->created_at->toIso8601String(),
            'expires_on' => $payment->expires_on?->toIso8601String(),
        ];
    }
}
