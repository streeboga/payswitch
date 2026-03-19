<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Streeboga\PaymentData\Models\PaymentIntent;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateClientSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $clientSecret = $request->input('client_secret');

        if (! $clientSecret) {
            return $this->errorResponse('client_secret is required', 'client_secret_required');
        }

        $paymentKey = $request->route()->parameter('paymentKey');
        $merchantId = $request->attributes->get('merchant_id');

        $payment = PaymentIntent::where('key', $paymentKey)
            ->where('merchant_account_id', $merchantId)
            ->first();

        if (! $payment || ! hash_equals($payment->client_secret, $clientSecret)) {
            return $this->errorResponse('Invalid client_secret', 'invalid_client_secret');
        }

        if ($payment->expires_on && $payment->expires_on->isPast()) {
            return $this->errorResponse('Payment session has expired', 'session_expired');
        }

        $request->attributes->set('payment_intent', $payment);

        return $next($request);
    }

    private function errorResponse(string $detail, string $code): Response
    {
        return response()->json([
            'errors' => [
                [
                    'status' => '403',
                    'code' => $code,
                    'detail' => $detail,
                ],
            ],
        ], 403)->header('Content-Type', 'application/vnd.api+json');
    }
}
