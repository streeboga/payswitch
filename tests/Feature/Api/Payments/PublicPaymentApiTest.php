<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
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
    $this->profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'test',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test_xxx'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    $this->payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'amount' => 10000,
        'currency' => 'RUB',
        'status' => PaymentStatus::RequiresPaymentMethod,
        'return_url' => 'https://merchant.example.com/result',
        'session_expiry' => 900,
        'expires_on' => now()->addMinutes(15),
    ]);
});

function publicHeaders($merchant): array
{
    return ['api-key' => $merchant->publishable_key];
}

// --- show() ---

test('public show returns limited payment fields', function () {
    $response = $this->getJson(
        '/api/v1/payments/'.$this->payment->key.'?client_secret='.$this->payment->client_secret,
        publicHeaders($this->merchant),
    );

    $response->assertOk();
    $attrs = $response->json('data.attributes');
    expect($attrs)->toHaveKeys(['status', 'amount', 'currency']);
    expect($attrs)->not->toHaveKey('client_secret');
    expect($attrs)->not->toHaveKey('connector');
});

test('public show rejects without client_secret', function () {
    $response = $this->getJson(
        '/api/v1/payments/'.$this->payment->key,
        publicHeaders($this->merchant),
    );

    $response->assertStatus(403);
});

// --- paymentMethods() ---

test('payment methods returns enabled methods for the profile', function () {
    $response = $this->getJson(
        '/api/v1/payments/'.$this->payment->key.'/payment-methods?client_secret='.$this->payment->client_secret,
        publicHeaders($this->merchant),
    );

    $response->assertOk();
    $methods = $response->json('data');
    expect($methods)->toBeArray();
    expect($methods)->not->toBeEmpty();
    expect($methods[0])->toHaveKey('payment_method', 'card');
});

test('payment methods returns empty when no active connectors', function () {
    MerchantConnectorAccount::query()->update(['disabled' => true]);

    $response = $this->getJson(
        '/api/v1/payments/'.$this->payment->key.'/payment-methods?client_secret='.$this->payment->client_secret,
        publicHeaders($this->merchant),
    );

    $response->assertOk();
    expect($response->json('data'))->toBeEmpty();
});

// --- confirm() ---

test('public confirm triggers redirect flow', function () {
    $response = $this->postJson(
        '/api/v1/payments/'.$this->payment->key.'/confirm',
        [
            'client_secret' => $this->payment->client_secret,
            'payment_method' => 'card',
        ],
        publicHeaders($this->merchant),
    );

    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'requires_customer_action');

    $metadata = $response->json('data.attributes.metadata');
    expect($metadata)->toHaveKey('redirect_url');
    expect($metadata['redirect_url'])->toContain('https://test-psp.example.com/pay/');
});

test('public confirm rejects with invalid client_secret', function () {
    $response = $this->postJson(
        '/api/v1/payments/'.$this->payment->key.'/confirm',
        [
            'client_secret' => 'invalid_secret',
            'payment_method' => 'card',
        ],
        publicHeaders($this->merchant),
    );

    $response->assertStatus(403);
});

test('public confirm rejects expired session', function () {
    $this->payment->update(['expires_on' => now()->subMinute()]);

    $response = $this->postJson(
        '/api/v1/payments/'.$this->payment->key.'/confirm',
        [
            'client_secret' => $this->payment->client_secret,
            'payment_method' => 'card',
        ],
        publicHeaders($this->merchant),
    );

    $response->assertStatus(403);
});
