<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Streeboga\PaymentData\Models\PaymentIntent;

final class PaymentsController
{
    public function index(Request $request): Response
    {
        $payments = PaymentIntent::query()
            ->latest()
            ->paginate(20)
            ->through(fn ($payment) => [
                'id' => $payment->key,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status->value,
                'connector' => $payment->connector,
                'customer_id' => $payment->customer_id,
                'created_at' => $payment->created_at->format('d.m.Y H:i'),
            ]);

        return Inertia::render('dashboard/payments/index', [
            'payments' => $payments,
        ]);
    }

    public function show(string $paymentKey): Response
    {
        $payment = PaymentIntent::where('key', $paymentKey)->firstOrFail();
        $payment->load(['paymentAttempts', 'refunds']);

        return Inertia::render('dashboard/payments/show', [
            'payment' => [
                'id' => $payment->key,
                'amount' => $payment->amount,
                'amount_received' => $payment->amount_received,
                'amount_capturable' => $payment->amount_capturable,
                'currency' => $payment->currency,
                'status' => $payment->status->value,
                'capture_method' => $payment->capture_method->value,
                'connector' => $payment->connector,
                'customer_id' => $payment->customer_id,
                'description' => $payment->description,
                'error_code' => $payment->error_code,
                'error_message' => $payment->error_message,
                'metadata' => $payment->metadata,
                'attempt_count' => $payment->attempt_count,
                'created_at' => $payment->created_at->format('d.m.Y H:i:s'),
                'attempts' => $payment->paymentAttempts->map(fn ($a) => [
                    'connector' => $a->connector,
                    'status' => $a->status,
                    'amount' => $a->amount,
                    'error_code' => $a->error_code,
                    'created_at' => $a->created_at->format('H:i:s'),
                ]),
                'refunds' => $payment->refunds->map(fn ($r) => [
                    'id' => $r->key,
                    'amount' => $r->amount,
                    'status' => $r->status->value,
                    'reason' => $r->reason,
                    'created_at' => $r->created_at->format('d.m.Y H:i'),
                ]),
            ],
        ]);
    }
}
