<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
    $this->mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $profile->id,
        'connector_name' => 'test',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['api_key' => 'test'],
        'test_mode' => true,
    ]);
});

test('returns 404 for non-existent merchant key', function () {
    $this->postJson("/api/v1/webhooks/fake_merchant/{$this->mca->key}", ['type' => 'test'])
        ->assertStatus(404);
});

test('returns 404 for non-existent mca key', function () {
    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/fake_mca", ['type' => 'test'])
        ->assertStatus(404);
});

test('returns 404 when mca does not belong to merchant', function () {
    $org2 = Organization::create(['name' => 'Org2']);
    $merchant2 = MerchantAccount::create(['org_id' => $org2->id, 'name' => 'M2']);

    $this->postJson("/api/v1/webhooks/{$merchant2->key}/{$this->mca->key}", ['type' => 'test'])
        ->assertStatus(404);
});

test('accepts webhook from test connector without signature', function () {
    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'payment.succeeded',
    ])->assertOk()->assertJson(['status' => 'ok']);
});

test('rejects webhook from stripe connector without signature header', function () {
    $this->mca->update(['connector_name' => 'stripe']);

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'payment_intent.succeeded',
    ])->assertStatus(401);
});

test('processes payment status update from webhook', function () {
    $this->mca->update(['connector_name' => 'cloudpayments']);

    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000,
        'currency' => 'USD',
        'status' => PaymentStatus::Processing,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
    ]);

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'payment.succeeded',
        'InvoiceId' => $payment->key,
    ])->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
});

test('ignores webhook with invalid payment status transition', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000,
        'currency' => 'USD',
        'status' => PaymentStatus::Succeeded,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
    ]);

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'payment.canceled',
        'data' => ['object' => ['metadata' => ['payment_id' => $payment->key]]],
    ])->assertOk();

    // Status should NOT change
    expect($payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
});

test('ignores webhook with unknown payment id', function () {
    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'payment.succeeded',
        'data' => ['object' => ['metadata' => ['payment_id' => 'pay_nonexistent']]],
    ])->assertOk();
});

test('does not leak internal error details in response', function () {
    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'payment.succeeded',
        'data' => 'invalid_data',
    ])->assertOk();

    // Response should be simple {status: ok}, no stack traces
});
