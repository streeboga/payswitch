<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\PaymentAttempt;
use Streeboga\PaymentData\Models\PaymentIntent;

/**
 * Simulates a PSP hosted payment page for the Test connector.
 * Shows approve/decline buttons, then updates payment status and redirects to return_url.
 */
final class TestPspController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    public function show(string $paymentKey): View
    {
        $payment = PaymentIntent::where('key', $paymentKey)->firstOrFail();

        return view('test-psp', [
            'payment' => $payment,
            'amount' => number_format($payment->amount / 100, 2, '.', ' '),
            'currency' => $payment->currency,
        ]);
    }

    public function complete(string $paymentKey, Request $request): RedirectResponse
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

        // Redirect to dashboard payment detail page
        $dashboardUrl = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');

        return redirect($dashboardUrl.'/payments/'.$payment->key.'?status='.$action);
    }
}
