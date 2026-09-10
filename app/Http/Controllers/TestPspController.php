<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\PaymentStatusChanged;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\PaymentAttempt;
use Streeboga\PaymentData\Models\PaymentIntent;

/**
 * JSON API for the Test PSP simulator.
 * The React SPA at /test-psp/{paymentKey} consumes these endpoints.
 */
final class TestPspController extends Controller
{
    public function show(string $paymentKey): JsonResponse
    {
        $payment = PaymentIntent::where('key', $paymentKey)->firstOrFail();

        return response()->json([
            'data' => [
                'type' => 'test_psp_payment',
                'id' => $payment->key,
                'attributes' => [
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'status' => $payment->status->value,
                    'description' => $payment->description,
                    'return_url' => $payment->return_url,
                ],
            ],
        ]);
    }

    public function complete(string $paymentKey, Request $request): JsonResponse
    {
        $request->validate([
            'action' => ['required', 'in:approve,decline'],
        ]);

        $action = $request->input('action');
        $payment = PaymentIntent::where('key', $paymentKey)->firstOrFail();
        $previousStatus = $payment->status->value;

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

        // Без этого события тестовый платёж проходил молча: ни вебхука
        // мерчанту, ни пополнения кошелька — то есть проверить сквозной путь
        // тестовым коннектором было нельзя.
        event(new PaymentStatusChanged($payment->refresh(), $previousStatus));

        $returnUrl = $payment->return_url;
        if ($returnUrl) {
            $separator = str_contains($returnUrl, '?') ? '&' : '?';
            $returnUrl .= $separator.'payment_id='.$payment->key.'&status='.($action === 'approve' ? 'success' : 'failed');
        }

        $dashboardUrl = rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/');

        return response()->json([
            'data' => [
                'type' => 'test_psp_payment',
                'id' => $payment->key,
                'attributes' => [
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'status' => $payment->status->value,
                    'success' => $action === 'approve',
                    'return_url' => $returnUrl,
                    'dashboard_url' => $dashboardUrl.'/payments/'.$payment->key,
                ],
            ],
        ]);
    }
}
