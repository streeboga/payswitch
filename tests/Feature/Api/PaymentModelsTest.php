<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Models\Customer;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentAttempt;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\Refund;
use Streeboga\PaymentData\Models\WebhookEvent;

uses(RefreshDatabase::class);

// --- PaymentIntent ---

test('payment intent auto-generates key with pay_ prefix', function () {
    $payment = createPaymentIntent();

    expect($payment->key)->toStartWith('pay_');
    expect($payment->client_secret)->toContain('_secret_');
});

test('payment intent casts status to PaymentStatus enum', function () {
    $payment = createPaymentIntent();

    expect($payment->status)->toBeInstanceOf(PaymentStatus::class);
    expect($payment->status)->toBe(PaymentStatus::RequiresPaymentMethod);
});

test('payment intent casts capture_method to CaptureMethod enum', function () {
    $payment = createPaymentIntent(['capture_method' => 'manual']);

    expect($payment->capture_method)->toBe(CaptureMethod::Manual);
});

test('payment intent has many attempts', function () {
    $payment = createPaymentIntent();
    PaymentAttempt::create([
        'payment_intent_id' => $payment->id,
        'connector' => 'stripe',
        'status' => 'succeeded',
        'amount' => $payment->amount,
    ]);

    expect($payment->attempts)->toHaveCount(1);
});

test('payment intent has many refunds', function () {
    $payment = createPaymentIntent(['status' => 'succeeded']);
    Refund::create([
        'payment_intent_id' => $payment->id,
        'merchant_account_id' => $payment->merchant_account_id,
        'amount' => 1000,
        'currency' => 'USD',
        'status' => RefundStatus::Succeeded,
    ]);

    expect($payment->refunds)->toHaveCount(1);
});

test('payment intent resolves by key column', function () {
    expect((new PaymentIntent)->getRouteKeyName())->toBe('key');
});

// --- Customer ---

test('customer auto-generates key with cus_ prefix', function () {
    $customer = createCustomer();

    expect($customer->key)->toStartWith('cus_');
});

test('customer is scoped to merchant', function () {
    $customer = createCustomer();

    expect($customer->merchantAccount)->not->toBeNull();
});

test('customer casts metadata to array', function () {
    $customer = createCustomer(['metadata' => ['tier' => 'premium']]);

    expect($customer->metadata)->toBeArray()->toHaveKey('tier');
});

// --- Refund ---

test('refund auto-generates key with ref_ prefix', function () {
    $payment = createPaymentIntent(['status' => 'succeeded']);
    $refund = Refund::create([
        'payment_intent_id' => $payment->id,
        'merchant_account_id' => $payment->merchant_account_id,
        'amount' => 1000,
        'currency' => 'USD',
        'status' => RefundStatus::Pending,
    ]);

    expect($refund->key)->toStartWith('ref_');
});

test('refund casts status to RefundStatus enum', function () {
    $payment = createPaymentIntent(['status' => 'succeeded']);
    $refund = Refund::create([
        'payment_intent_id' => $payment->id,
        'merchant_account_id' => $payment->merchant_account_id,
        'amount' => 1000,
        'currency' => 'USD',
        'status' => RefundStatus::Succeeded,
    ]);

    expect($refund->status)->toBe(RefundStatus::Succeeded);
});

test('refund belongs to payment intent', function () {
    $payment = createPaymentIntent(['status' => 'succeeded']);
    $refund = Refund::create([
        'payment_intent_id' => $payment->id,
        'merchant_account_id' => $payment->merchant_account_id,
        'amount' => 1000,
        'currency' => 'USD',
        'status' => RefundStatus::Succeeded,
    ]);

    expect($refund->paymentIntent->id)->toBe($payment->id);
});

// --- WebhookEvent ---

test('webhook event auto-generates key with evt_ prefix', function () {
    $payment = createPaymentIntent();
    $event = WebhookEvent::create([
        'event_type' => 'payment_succeeded',
        'merchant_account_id' => $payment->merchant_account_id,
        'payment_intent_id' => $payment->id,
        'content' => ['payment_id' => $payment->key],
    ]);

    expect($event->key)->toStartWith('evt_');
});

test('webhook event casts content to array', function () {
    $payment = createPaymentIntent();
    $event = WebhookEvent::create([
        'event_type' => 'payment_succeeded',
        'merchant_account_id' => $payment->merchant_account_id,
        'payment_intent_id' => $payment->id,
        'content' => ['test' => 'data'],
    ]);

    expect($event->content)->toBeArray()->toHaveKey('test');
});

// --- Helpers ---

function createMerchant(): MerchantAccount
{
    $org = Organization::create(['name' => 'Test Org']);

    return MerchantAccount::create(['org_id' => $org->id, 'name' => 'Merchant']);
}

function createPaymentIntent(array $overrides = []): PaymentIntent
{
    $merchant = createMerchant();

    return PaymentIntent::create(array_merge([
        'merchant_account_id' => $merchant->id,
        'amount' => 6540,
        'currency' => 'USD',
        'status' => PaymentStatus::RequiresPaymentMethod,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
    ], $overrides));
}

function createCustomer(array $overrides = []): Customer
{
    $merchant = createMerchant();

    return Customer::create(array_merge([
        'merchant_account_id' => $merchant->id,
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ], $overrides));
}
