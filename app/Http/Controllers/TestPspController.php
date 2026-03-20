<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\PaymentAttempt;
use Streeboga\PaymentData\Models\PaymentIntent;

/**
 * Simulates a PSP hosted payment page for the Test connector.
 * Flow: payment page → approve/decline → result page → return to merchant.
 */
final class TestPspController extends Controller
{
    public function show(string $paymentKey): View
    {
        $payment = PaymentIntent::where('key', $paymentKey)->firstOrFail();

        return view('test-psp', [
            'payment' => $payment,
            'amount' => number_format($payment->amount / 100, 2, '.', ' '),
            'currency' => $payment->currency,
            'step' => 'checkout',
        ]);
    }

    public function complete(string $paymentKey, Request $request): View
    {
        $action = $request->input('action', 'approve');
        $payment = PaymentIntent::where('key', $paymentKey)->firstOrFail();

        if ($action === 'approve') {
            $payment->update([
                'status' => PaymentStatus::Succeeded,
                'amount_received' => $payment->amount,
                'amount_capturable' => 0,
            ]);

            PaymentAttempt::where('payment_intent_id', $payment->id)
                ->where('status', 'requires_action')
                ->update(['status' => 'succeeded']);
        } else {
            $payment->update([
                'status' => PaymentStatus::Failed,
                'error_code' => 'declined',
                'error_message' => 'Payment declined by customer',
            ]);

            PaymentAttempt::where('payment_intent_id', $payment->id)
                ->where('status', 'requires_action')
                ->update([
                    'status' => 'failed',
                    'error_code' => 'declined',
                    'error_message' => 'Payment declined by customer',
                ]);
        }

        $returnUrl = $payment->return_url;
        if ($returnUrl) {
            $separator = str_contains($returnUrl, '?') ? '&' : '?';
            $returnUrl .= $separator.'payment_id='.$payment->key.'&status='.($action === 'approve' ? 'success' : 'failed');
        }

        $dashboardUrl = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');

        return view('test-psp', [
            'payment' => $payment,
            'amount' => number_format($payment->amount / 100, 2, '.', ' '),
            'currency' => $payment->currency,
            'step' => 'result',
            'success' => $action === 'approve',
            'returnUrl' => $returnUrl,
            'dashboardUrl' => $dashboardUrl.'/payments/'.$payment->key,
        ]);
    }
}
