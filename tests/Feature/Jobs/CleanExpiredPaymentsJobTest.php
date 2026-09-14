<?php

declare(strict_types=1);

use App\Events\PaymentStatusChanged;
use App\Jobs\CleanExpiredPaymentsJob;
use App\Repositories\Contracts\PaymentIntentRepositoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\WebhookEvent;

uses(RefreshDatabase::class);

test('expiry tells the merchant exactly once', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000, 'currency' => 'RUB',
        'status' => PaymentStatus::RequiresCustomerAction,
        'capture_method' => 'automatic',
        'attempt_count' => 1,
        'expires_on' => now()->subMinutes(5),
    ]);

    (new CleanExpiredPaymentsJob)->handle(app(PaymentIntentRepositoryInterface::class));
    (new CleanExpiredPaymentsJob)->handle(app(PaymentIntentRepositoryInterface::class));

    $events = WebhookEvent::where('payment_intent_id', $payment->id)->get();
    expect($events)->toHaveCount(1)
        ->and($events[0]->content['status'])->toBe('expired')
        ->and(Activity::where('log_name', 'payment')->count())->toBe(1);
});

test('does not expire a payment a concurrent webhook just made succeeded', function () {
    Event::fake([PaymentStatusChanged::class]);
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000, 'currency' => 'RUB',
        'status' => PaymentStatus::RequiresCustomerAction,
        'capture_method' => 'automatic',
        'attempt_count' => 1,
        'expires_on' => now()->subMinutes(5),
    ]);

    // Pay от PSP коммитит `succeeded` после того, как джоба прочитала пачку.
    $raced = false;
    PaymentIntent::retrieved(function () use ($payment, &$raced) {
        if (! $raced) {
            $raced = true;
            DB::table('payment_intents')->where('id', $payment->id)->update(['status' => PaymentStatus::Succeeded->value]);
        }
    });

    (new CleanExpiredPaymentsJob)->handle(app(PaymentIntentRepositoryInterface::class));

    expect($raced)->toBeTrue()
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
    Event::assertNotDispatched(PaymentStatusChanged::class);
});

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
});

test('expires payments past expires_on in requires_payment_method', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000, 'currency' => 'RUB',
        'status' => PaymentStatus::RequiresPaymentMethod,
        'capture_method' => 'automatic',
        'attempt_count' => 1,
        'expires_on' => now()->subMinutes(5),
    ]);

    (new CleanExpiredPaymentsJob)->handle(app(PaymentIntentRepositoryInterface::class));

    expect($payment->fresh()->status)->toBe(PaymentStatus::Expired);
});

test('expires payments in requires_confirmation', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000, 'currency' => 'RUB',
        'status' => PaymentStatus::RequiresConfirmation,
        'capture_method' => 'automatic',
        'attempt_count' => 1,
        'expires_on' => now()->subMinutes(5),
    ]);

    (new CleanExpiredPaymentsJob)->handle(app(PaymentIntentRepositoryInterface::class));

    expect($payment->fresh()->status)->toBe(PaymentStatus::Expired);
});

test('expires payments in requires_customer_action', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000, 'currency' => 'RUB',
        'status' => PaymentStatus::RequiresCustomerAction,
        'capture_method' => 'automatic',
        'attempt_count' => 1,
        'expires_on' => now()->subMinutes(5),
    ]);

    (new CleanExpiredPaymentsJob)->handle(app(PaymentIntentRepositoryInterface::class));

    expect($payment->fresh()->status)->toBe(PaymentStatus::Expired);
});

test('does not expire non-expired payment', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000, 'currency' => 'RUB',
        'status' => PaymentStatus::RequiresPaymentMethod,
        'capture_method' => 'automatic',
        'attempt_count' => 1,
        'expires_on' => now()->addMinutes(10),
    ]);

    (new CleanExpiredPaymentsJob)->handle(app(PaymentIntentRepositoryInterface::class));

    expect($payment->fresh()->status)->toBe(PaymentStatus::RequiresPaymentMethod);
});

test('does not expire succeeded payment even if past expires_on', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000, 'currency' => 'RUB',
        'status' => PaymentStatus::Succeeded,
        'capture_method' => 'automatic',
        'attempt_count' => 1,
        'amount_received' => 5000,
        'expires_on' => now()->subMinutes(5),
    ]);

    (new CleanExpiredPaymentsJob)->handle(app(PaymentIntentRepositoryInterface::class));

    expect($payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
});

test('does not expire payment without expires_on', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000, 'currency' => 'RUB',
        'status' => PaymentStatus::RequiresPaymentMethod,
        'capture_method' => 'automatic',
        'attempt_count' => 1,
        'expires_on' => null,
    ]);

    (new CleanExpiredPaymentsJob)->handle(app(PaymentIntentRepositoryInterface::class));

    expect($payment->fresh()->status)->toBe(PaymentStatus::RequiresPaymentMethod);
});
