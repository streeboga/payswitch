<?php

declare(strict_types=1);

use App\Jobs\CleanExpiredPaymentsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;

uses(RefreshDatabase::class);

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

    (new CleanExpiredPaymentsJob)->handle();

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

    (new CleanExpiredPaymentsJob)->handle();

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

    (new CleanExpiredPaymentsJob)->handle();

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

    (new CleanExpiredPaymentsJob)->handle();

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

    (new CleanExpiredPaymentsJob)->handle();

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

    (new CleanExpiredPaymentsJob)->handle();

    expect($payment->fresh()->status)->toBe(PaymentStatus::RequiresPaymentMethod);
});
