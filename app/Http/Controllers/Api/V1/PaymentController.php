<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\Payment\CapturePaymentRequest;
use App\Http\Requests\Api\Payment\ConfirmPaymentRequest;
use App\Http\Requests\Api\Payment\StorePaymentRequest;
use App\Http\Resources\PaymentIntentResource;
use App\Services\PaymentService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

#[Group(name: 'Payments', description: 'Create, confirm, capture and cancel payment intents', weight: 1)]
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
    #[Response(201, description: 'Payment intent created')]
    #[Response(422, description: 'Validation error')]
    public function store(StorePaymentRequest $request): JsonResponse
    {
        $dto = $request->toDto();
        $merchantAccountId = $request->attributes->get('merchant_id');

        $payment = $this->paymentService->create($dto, $merchantAccountId);

        if ($dto->confirm) {
            $payment = $this->paymentService->confirm($payment->key, $request->toConfirmDto(), $merchantAccountId);
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
    #[PathParameter('paymentKey', description: 'Payment intent public key', example: 'pi_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Payment intent details')]
    #[Response(404, description: 'Payment not found')]
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
    #[PathParameter('paymentKey', description: 'Payment intent public key', example: 'pi_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Payment intent confirmed')]
    #[Response(404, description: 'Payment not found')]
    #[Response(422, description: 'Validation error')]
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
    #[PathParameter('paymentKey', description: 'Payment intent public key', example: 'pi_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Payment captured')]
    #[Response(404, description: 'Payment not found')]
    public function capture(string $paymentKey, CapturePaymentRequest $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');

        $payment = $this->paymentService->capture($paymentKey, $request->toDto()->amount_to_capture, $merchantAccountId);

        return (new PaymentIntentResource($payment))->toResponse($request);
    }

    /**
     * Cancel a payment intent.
     *
     * Cancels a payment intent that has not yet been captured or completed.
     */
    #[PathParameter('paymentKey', description: 'Payment intent public key', example: 'pi_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Payment cancelled')]
    #[Response(404, description: 'Payment not found')]
    public function cancel(string $paymentKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $payment = $this->paymentService->cancel($paymentKey, $merchantAccountId);

        return (new PaymentIntentResource($payment))->toResponse($request);
    }

    /**
     * Sync a payment intent with the PSP.
     *
     * Polls the PSP for the current payment status and updates the local record.
     * Only payments in `requires_customer_action` or `processing` state can be synced.
     */
    #[PathParameter('paymentKey', description: 'Payment intent public key', example: 'pi_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Payment synced')]
    #[Response(400, description: 'Payment not in syncable state')]
    #[Response(404, description: 'Payment not found')]
    #[Response(502, description: 'Connector unavailable')]
    public function sync(string $paymentKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $payment = $this->paymentService->sync($paymentKey, $merchantAccountId);

        return (new PaymentIntentResource($payment))->toResponse($request);
    }
}
