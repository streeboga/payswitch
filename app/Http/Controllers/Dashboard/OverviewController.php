<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use Inertia\Inertia;
use Inertia\Response;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\Customer;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\Refund;

final class OverviewController
{
    public function __invoke(): Response
    {
        return Inertia::render('dashboard/overview', [
            'stats' => [
                'total_payments' => PaymentIntent::count(),
                'succeeded_payments' => PaymentIntent::where('status', PaymentStatus::Succeeded)->count(),
                'failed_payments' => PaymentIntent::where('status', PaymentStatus::Failed)->count(),
                'total_revenue' => PaymentIntent::where('status', PaymentStatus::Succeeded)->sum('amount_received'),
                'total_refunds' => Refund::count(),
                'total_refunded' => Refund::where('status', 'succeeded')->sum('amount'),
                'total_merchants' => MerchantAccount::count(),
                'total_customers' => Customer::count(),
            ],
            'recent_payments' => PaymentIntent::latest()->limit(10)->get()->map(fn ($p) => [
                'id' => $p->key,
                'amount' => $p->amount,
                'currency' => $p->currency,
                'status' => $p->status->value,
                'connector' => $p->connector,
                'created_at' => $p->created_at->diffForHumans(),
            ]),
        ]);
    }
}
