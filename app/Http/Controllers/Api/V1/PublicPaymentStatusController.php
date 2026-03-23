<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Streeboga\PaymentData\Models\PaymentIntent;

final class PublicPaymentStatusController extends Controller
{
    /**
     * Get payment status (lightweight polling endpoint).
     *
     * Returns only status, amount, and currency — optimized for frequent
     * polling during QR code inline payment flows.
     */
    public function __invoke(Request $request, string $paymentKey): JsonResponse
    {
        /** @var PaymentIntent $payment */
        $payment = $request->attributes->get('payment_intent');

        return response()->json([
            'data' => [
                'status' => $payment->status->value,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
            ],
        ]);
    }
}
