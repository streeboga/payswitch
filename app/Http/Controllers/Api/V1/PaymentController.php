<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\JsonApiResponse;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Streeboga\PaymentData\Models\PaymentIntent;

final class PaymentController extends Controller
{
    use JsonApiResponse;

    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'data.attributes.amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'data.attributes.currency' => ['required', 'string', 'size:3'],
            'data.attributes.capture_method' => ['sometimes', 'string', 'in:automatic,manual'],
            'data.attributes.authentication_type' => ['sometimes', 'string', 'in:three_ds,no_three_ds'],
            'data.attributes.customer_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'data.attributes.description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'data.attributes.return_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'data.attributes.metadata' => ['sometimes', 'nullable', 'array', 'max:50'],
            'data.attributes.session_expiry' => ['sometimes', 'integer', 'min:60', 'max:86400'],
            'data.attributes.payment_id' => ['sometimes', 'string', 'max:40'],
            'data.attributes.confirm' => ['sometimes', 'boolean'],
            'data.attributes.payment_method' => ['required_if:data.attributes.confirm,true', 'string'],
            'data.attributes.payment_method_data' => ['required_if:data.attributes.confirm,true', 'array'],
        ]);

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

    public function confirm(string $paymentKey, Request $request): JsonResponse
    {
        $request->validate([
            'data.attributes.payment_method' => ['required', 'string'],
            'data.attributes.payment_method_data' => ['required', 'array'],
        ]);

        $merchantAccountId = $request->attributes->get('merchant_id');
        $attributes = $request->input('data.attributes', []);

        $payment = $this->paymentService->confirm($paymentKey, $attributes, $merchantAccountId);

        return $this->jsonApiResource(
            model: $payment,
            type: 'payments',
            attributes: $this->paymentAttributes($payment),
        );
    }

    public function capture(string $paymentKey, Request $request): JsonResponse
    {
        $request->validate([
            'data.attributes.amount_to_capture' => ['required', 'integer', 'min:1'],
        ]);

        $merchantAccountId = $request->attributes->get('merchant_id');
        $amount = (int) $request->input('data.attributes.amount_to_capture');

        $payment = $this->paymentService->capture($paymentKey, $amount, $merchantAccountId);

        return $this->jsonApiResource(
            model: $payment,
            type: 'payments',
            attributes: $this->paymentAttributes($payment),
        );
    }

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
