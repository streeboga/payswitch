<?php

declare(strict_types=1);

use App\Services\WebhookReceiverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;

covers(WebhookReceiverService::class);

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
        ->assertStatus(404)
        ->assertJson(['status' => 'ignored']);
});

test('returns 404 for non-existent mca key', function () {
    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/fake_mca", ['type' => 'test'])
        ->assertStatus(404)
        ->assertJson(['status' => 'ignored']);
});

test('returns 404 when mca does not belong to merchant', function () {
    $org2 = Organization::create(['name' => 'Org2']);
    $merchant2 = MerchantAccount::create(['org_id' => $org2->id, 'name' => 'M2']);

    $this->postJson("/api/v1/webhooks/{$merchant2->key}/{$this->mca->key}", ['type' => 'test'])
        ->assertStatus(404)
        ->assertJson(['status' => 'ignored']);
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
    ])->assertStatus(401)
        ->assertJson(['status' => 'invalid_signature']);
});

test('processes payment status update from webhook and sets amount_received', function () {
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
    ])->assertOk()
        ->assertJson(['status' => 'ok']);

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe(PaymentStatus::Succeeded)
        ->and($fresh->amount_received)->toBe(5000);

    // Verify exact values in DB including connector field
    $this->assertDatabaseHas('payment_intents', [
        'id' => $payment->id,
        'status' => 'succeeded',
        'amount_received' => 5000,
        'connector' => 'cloudpayments',
    ]);
});

test('processes failed webhook and does not set amount_received', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 3000,
        'currency' => 'USD',
        'status' => PaymentStatus::Processing,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
    ]);

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'payment.failed',
        'payment_id' => $payment->key,
    ])->assertOk()
        ->assertJson(['status' => 'ok']);

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe(PaymentStatus::Failed)
        ->and($fresh->amount_received)->toBeNull();

    // Verify DB state: status changed, amount_received stays null
    $this->assertDatabaseHas('payment_intents', [
        'id' => $payment->id,
        'status' => 'failed',
        'connector' => 'test',
    ]);
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
        'payment_id' => $payment->key,
    ])->assertOk();

    $fresh = $payment->fresh();
    // Status should NOT change — Succeeded is terminal
    expect($fresh->status)->toBe(PaymentStatus::Succeeded)
        ->and($fresh->amount_received)->toBeNull();

    // Verify DB still has original status — no mutation
    $this->assertDatabaseHas('payment_intents', [
        'id' => $payment->id,
        'status' => 'succeeded',
    ]);
});

test('unknown webhook type does not change payment status', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 2500,
        'currency' => 'EUR',
        'status' => PaymentStatus::Processing,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
    ]);

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'some.unknown.event',
        'payment_id' => $payment->key,
    ])->assertOk()
        ->assertJson(['status' => 'ok']);

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe(PaymentStatus::Processing)
        ->and($fresh->amount_received)->toBeNull();

    // Verify DB unchanged — unknown event types must not mutate payment
    $this->assertDatabaseHas('payment_intents', [
        'id' => $payment->id,
        'status' => 'processing',
        'amount' => 2500,
        'currency' => 'EUR',
    ]);
});

test('webhook for non-existent payment still returns ok', function () {
    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'payment.succeeded',
        'payment_id' => 'pay_nonexistent_key_12345',
    ])->assertOk()
        ->assertJson(['status' => 'ok']);
});

test('webhook without extractable payment id returns ok without errors', function () {
    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'payment.succeeded',
        // no payment_id or data.object.metadata.payment_id
    ])->assertOk()
        ->assertJson(['status' => 'ok']);
});

test('does not leak internal error details in response', function () {
    $response = $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'payment.succeeded',
        'data' => 'invalid_data',
    ]);

    $response->assertOk()
        ->assertJson(['status' => 'ok'])
        ->assertJsonMissing(['exception'])
        ->assertJsonMissing(['trace']);
});

test('cancelled webhook transitions requires_confirmation payment to cancelled', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 7500,
        'currency' => 'USD',
        'status' => PaymentStatus::RequiresConfirmation,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
    ]);

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'payment.canceled',
        'payment_id' => $payment->key,
    ])->assertOk();

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe(PaymentStatus::Cancelled)
        ->and($fresh->amount_received)->toBeNull();

    // Verify exact DB state after cancellation webhook
    $this->assertDatabaseHas('payment_intents', [
        'id' => $payment->id,
        'status' => 'cancelled',
        'amount' => 7500,
    ]);
});
