<?php

declare(strict_types=1);

use App\Jobs\DeliverWebhookJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\WebhookEvent;
use Streeboga\PaymentData\Support\IdGenerator;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->profile = BusinessProfile::create([
        'merchant_account_id' => $this->merchant->id,
        'webhook_url' => 'https://merchant.example.com/webhook',
    ]);

    $this->rawKey = IdGenerator::apiKey('sandbox');
    ApiKey::create([
        'merchant_account_id' => $this->merchant->id,
        'key_hash' => bcrypt($this->rawKey),
        'key_prefix' => substr($this->rawKey, 0, 20),
        'name' => 'Test',
    ]);

    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'test',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => encrypt(json_encode(['auth_type' => 'HeaderKey', 'api_key' => 'sk_test'])),
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);
});

test('webhook event created on payment status change', function () {
    // Create and confirm payment → triggers PaymentStatusChanged → webhook event
    $create = $this->postJson('/api/v1/payments', [
        'data' => ['type' => 'payments', 'attributes' => [
            'amount' => 5000, 'currency' => 'USD', 'confirm' => true,
            'payment_method' => 'card',
            'payment_method_data' => ['card' => ['card_number' => '4242424242424242', 'card_exp_month' => '12', 'card_exp_year' => '2030', 'card_cvc' => '123']],
        ]],
    ], ['api-key' => $this->rawKey]);

    $this->assertDatabaseHas('webhook_events', [
        'event_type' => 'payment_succeeded',
        'merchant_account_id' => $this->merchant->id,
    ]);
});

test('webhook event has unique event_id', function () {
    $this->postJson('/api/v1/payments', [
        'data' => ['type' => 'payments', 'attributes' => [
            'amount' => 5000, 'currency' => 'USD', 'confirm' => true,
            'payment_method' => 'card',
            'payment_method_data' => ['card' => ['card_number' => '4242424242424242', 'card_exp_month' => '12', 'card_exp_year' => '2030', 'card_cvc' => '123']],
        ]],
    ], ['api-key' => $this->rawKey]);

    $event = WebhookEvent::first();
    expect($event->key)->toStartWith('evt_');
});

test('DeliverWebhookJob is dispatched on payment status change', function () {
    $this->postJson('/api/v1/payments', [
        'data' => ['type' => 'payments', 'attributes' => [
            'amount' => 5000, 'currency' => 'USD', 'confirm' => true,
            'payment_method' => 'card',
            'payment_method_data' => ['card' => ['card_number' => '4242424242424242', 'card_exp_month' => '12', 'card_exp_year' => '2030', 'card_cvc' => '123']],
        ]],
    ], ['api-key' => $this->rawKey]);

    Queue::assertPushed(DeliverWebhookJob::class);
});

test('webhook event content contains full payment object', function () {
    $this->postJson('/api/v1/payments', [
        'data' => ['type' => 'payments', 'attributes' => [
            'amount' => 5000, 'currency' => 'USD', 'confirm' => true,
            'payment_method' => 'card',
            'payment_method_data' => ['card' => ['card_number' => '4242424242424242', 'card_exp_month' => '12', 'card_exp_year' => '2030', 'card_cvc' => '123']],
        ]],
    ], ['api-key' => $this->rawKey]);

    $event = WebhookEvent::first();
    expect($event->content)->toBeArray()
        ->toHaveKey('payment_id')
        ->toHaveKey('status')
        ->toHaveKey('amount');
});

test('cancelled payment creates webhook event', function () {
    $create = $this->postJson('/api/v1/payments', [
        'data' => ['type' => 'payments', 'attributes' => ['amount' => 1000, 'currency' => 'USD']],
    ], ['api-key' => $this->rawKey]);

    $paymentId = $create->json('data.id');

    $this->postJson("/api/v1/payments/{$paymentId}/cancel", [], ['api-key' => $this->rawKey]);

    $this->assertDatabaseHas('webhook_events', [
        'event_type' => 'payment_cancelled',
    ]);
});
