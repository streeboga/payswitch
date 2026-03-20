<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DataTransferObjects\Payment\ConfirmPaymentData;
use App\Http\Resources\PaymentIntentResource;
use App\Http\Resources\PublicPaymentIntentResource;
use App\Services\PaymentService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

#[Group(name: 'Payment Widget', description: 'Public payment widget endpoints for retrieving, confirming payments and listing available payment methods', weight: 2)]
final class PublicPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    /**
     * Get a payment intent (public).
     *
     * Retrieves limited payment details for the payment widget. When called with a secret key,
     * returns the full payment intent resource instead.
     */
    #[PathParameter('paymentKey', description: 'Payment intent public key', example: 'pi_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Payment intent details')]
    #[Response(403, description: 'Invalid or missing client_secret')]
    #[Response(404, description: 'Payment not found')]
    public function show(string $paymentKey, Request $request): JsonResponse
    {
        if ($request->attributes->get('api_key_type') === 'secret') {
            $merchantAccountId = $request->attributes->get('merchant_id');
            $payment = $this->paymentService->find($paymentKey, $merchantAccountId);

            return (new PaymentIntentResource($payment))->toResponse($request);
        }

        $payment = $request->attributes->get('payment_intent');

        return (new PublicPaymentIntentResource($payment))->toResponse($request);
    }

    /**
     * Confirm a payment intent (public).
     *
     * Confirms a payment with the selected payment method. Typically triggers a redirect flow
     * for customer authentication. When called with a secret key, returns the full resource.
     */
    #[PathParameter('paymentKey', description: 'Payment intent public key', example: 'pi_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Payment intent confirmed')]
    #[Response(403, description: 'Invalid or missing client_secret / expired session')]
    #[Response(422, description: 'Validation error')]
    public function confirm(string $paymentKey, Request $request): JsonResponse
    {
        $request->validate([
            'payment_method' => ['required', 'string'],
            'payment_method_data' => ['sometimes', 'array'],
            'connector' => ['sometimes', 'string'],
        ]);

        $dto = ConfirmPaymentData::from($request->only([
            'payment_method',
            'payment_method_data',
            'connector',
        ]));

        $merchantAccountId = $request->attributes->get('merchant_id');
        $payment = $this->paymentService->confirm($paymentKey, $dto, $merchantAccountId);

        if ($request->attributes->get('api_key_type') === 'secret') {
            return (new PaymentIntentResource($payment))->toResponse($request);
        }

        return (new PublicPaymentIntentResource($payment))->toResponse($request);
    }

    /**
     * List available payment methods.
     *
     * Returns the unique payment methods enabled across active connectors for the payment's
     * business profile. Used by the widget to render payment method options.
     */
    #[PathParameter('paymentKey', description: 'Payment intent public key', example: 'pi_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Available payment methods')]
    #[Response(403, description: 'Invalid or missing client_secret')]
    #[Response(404, description: 'Payment not found')]
    public function paymentMethods(string $paymentKey, Request $request): JsonResponse
    {
        $payment = $request->attributes->get('payment_intent')
            ?? $this->paymentService->find($paymentKey, $request->attributes->get('merchant_id'));

        $locale = $request->query('locale');
        $methods = $this->paymentService->getAvailablePaymentMethods($payment, $locale);

        return response()->json([
            'data' => $methods->map(fn (array $method): array => [
                'type' => 'payment_methods',
                'id' => $method['payment_method'],
                'attributes' => $method,
            ])->all(),
        ], 200, ['Content-Type' => 'application/vnd.api+json']);
    }
}
