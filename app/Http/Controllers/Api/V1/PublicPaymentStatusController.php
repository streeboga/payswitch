<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\PaymentService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Streeboga\PaymentData\Models\PaymentIntent;

#[Group(name: 'Payment Widget', description: 'Public payment widget endpoints for retrieving, confirming payments and listing available payment methods', weight: 2)]
final class PublicPaymentStatusController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    /**
     * Get payment status for polling
     *
     * Lightweight endpoint optimized for frequent polling during QR code inline
     * payment flows. Returns only status, amount, and currency.
     */
    #[PathParameter('paymentKey', description: 'Payment intent public key', example: 'pi_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Payment status')]
    #[Response(403, description: 'Invalid or missing client_secret')]
    #[Response(404, description: 'Payment not found')]
    public function __invoke(Request $request, string $paymentKey): JsonResponse
    {
        /** @var PaymentIntent $payment */
        $payment = $request->attributes->get('payment_intent');

        return response()->json([
            'data' => [
                'type' => 'payment_status',
                'id' => $payment->key,
                'attributes' => [
                    'status' => $payment->status->value,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                ],
            ],
        ], 200, ['Content-Type' => 'application/vnd.api+json']);
    }
}
