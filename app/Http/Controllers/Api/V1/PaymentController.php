<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DataTransferObjects\Payment\ConfirmPaymentData;
use App\Http\Requests\Api\Payment\CapturePaymentRequest;
use App\Http\Requests\Api\Payment\ConfirmPaymentRequest;
use App\Http\Requests\Api\Payment\StorePaymentRequest;
use App\Http\Resources\PaymentIntentResource;
use App\Services\PaymentService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

#[Group(name: 'Payments', weight: 1)]
final class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    /**
     * Create a payment intent.
     *
     * Creates a new payment intent for the authenticated merchant. The payment can optionally
     * be confirmed immediately by setting the confirm flag to true and providing payment method data.
     */
    public function store(StorePaymentRequest $request): JsonResponse
    {
        $dto = $request->toDto();
        $merchantAccountId = $request->attributes->get('merchant_id');

        $payment = $this->paymentService->create($dto, $merchantAccountId);

        if ($dto->confirm) {
            $confirmData = ConfirmPaymentData::from($request->validated('data.attributes') ?? []);
            $payment = $this->paymentService->confirm($payment->key, $confirmData, $merchantAccountId);
        }

        return (new PaymentIntentResource($payment))
            ->withStatus(201)
            ->withHeader('Location', url("/api/v1/payments/{$payment->key}"))
            ->toResponse($request);
    }

    /**
     * Get a payment intent.
     *
     * Retrieves the details of a payment intent that belongs to the authenticated merchant.
     */
    public function show(string $paymentKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $payment = $this->paymentService->find($paymentKey, $merchantAccountId);

        return (new PaymentIntentResource($payment))->toResponse($request);
    }

    /**
     * Confirm a payment intent.
     *
     * Confirms a payment intent with the provided payment method and payment method data.
     */
    public function confirm(string $paymentKey, ConfirmPaymentRequest $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');

        $payment = $this->paymentService->confirm($paymentKey, $request->toDto(), $merchantAccountId);

        return (new PaymentIntentResource($payment))->toResponse($request);
    }

    /**
     * Capture a payment intent.
     *
     * Captures a previously authorized payment intent. Allows partial capture.
     */
    public function capture(string $paymentKey, CapturePaymentRequest $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $amount = (int) $request->input('data.attributes.amount_to_capture');

        $payment = $this->paymentService->capture($paymentKey, $amount, $merchantAccountId);

        return (new PaymentIntentResource($payment))->toResponse($request);
    }

    /**
     * Cancel a payment intent.
     *
     * Cancels a payment intent that has not yet been captured or completed.
     */
    public function cancel(string $paymentKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $payment = $this->paymentService->cancel($paymentKey, $merchantAccountId);

        return (new PaymentIntentResource($payment))->toResponse($request);
    }
}
