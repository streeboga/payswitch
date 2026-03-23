<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Support\IdGenerator;

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

test('payment methods returns enabled methods in JSON:API format', function () {
    $response = $this->getJson(
        '/api/v1/payments/'.$this->payment->key.'/payment-methods?client_secret='.$this->payment->client_secret,
        publicHeaders($this->merchant),
    );

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.api+json');

    $data = $response->json('data');
    expect($data)->toHaveKeys(['type', 'attributes']);
    expect($data['type'])->toBe('payment_methods');

    $attrs = $data['attributes'];
    expect($attrs)->toHaveKeys(['mode', 'methods', 'connectors']);
    expect($attrs['mode'])->toBe('direct_methods');
    expect($attrs['methods'])->not->toBeEmpty();
    expect($attrs['methods'][0]['method'])->toBe('card');
    expect($attrs['methods'][0]['type'])->toBe('direct');
});

test('payment methods include display_name from getMethodDisplayName', function () {
    $response = $this->getJson(
        '/api/v1/payments/'.$this->payment->key.'/payment-methods?client_secret='.$this->payment->client_secret.'&locale=ru',
        publicHeaders($this->merchant),
    );

    $response->assertOk();
    $methods = $response->json('data.attributes.methods');
    expect($methods[0]['display_name'])->toBe('Банковская карта');
});

test('payment methods fallback to en when locale not found', function () {
    $response = $this->getJson(
        '/api/v1/payments/'.$this->payment->key.'/payment-methods?client_secret='.$this->payment->client_secret.'&locale=fr',
        publicHeaders($this->merchant),
    );

    $response->assertOk();
    // French not available, should fall back to en
    expect($response->json('data.attributes.methods.0.display_name'))->toBe('Card');
});

test('payment methods work without display_config', function () {
    $response = $this->getJson(
        '/api/v1/payments/'.$this->payment->key.'/payment-methods?client_secret='.$this->payment->client_secret,
        publicHeaders($this->merchant),
    );

    $response->assertOk();
    $attrs = $response->json('data.attributes');
    expect($attrs['mode'])->toBe('direct_methods');
    expect($attrs['methods'][0]['method'])->toBe('card');
    expect($attrs['methods'][0]['session_type'])->toBe('redirect');
});

test('payment methods returns none mode when no active connectors', function () {
    MerchantConnectorAccount::query()->update(['disabled' => true]);

    $response = $this->getJson(
        '/api/v1/payments/'.$this->payment->key.'/payment-methods?client_secret='.$this->payment->client_secret,
        publicHeaders($this->merchant),
    );

    $response->assertOk();
    $attrs = $response->json('data.attributes');
    expect($attrs['mode'])->toBe('none');
    expect($attrs['methods'])->toBeEmpty();
    expect($attrs['connectors'])->toBeEmpty();
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
    expect($metadata['redirect_url'])->toContain('/test-psp/');
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

// --- Secret key regression tests ---

function secretHeaders($rawKey): array
{
    return ['api-key' => $rawKey];
}

test('secret key show returns full payment intent resource', function () {
    $rawKey = IdGenerator::apiKey('sandbox');
    ApiKey::create([
        'merchant_account_id' => $this->merchant->id,
        'key_hash' => hash('sha256', $rawKey),
        'key_prefix' => substr($rawKey, 0, 20),
        'name' => 'Secret',
    ]);

    $response = $this->getJson(
        '/api/v1/payments/'.$this->payment->key,
        secretHeaders($rawKey),
    );

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertJsonPath('data.type', 'payments')
        ->assertJsonPath('data.id', $this->payment->key);

    $attrs = $response->json('data.attributes');
    expect($attrs)->toHaveKeys(['status', 'amount', 'currency', 'client_secret']);
});

test('secret key confirm returns full payment intent resource', function () {
    $rawKey = IdGenerator::apiKey('sandbox');
    ApiKey::create([
        'merchant_account_id' => $this->merchant->id,
        'key_hash' => hash('sha256', $rawKey),
        'key_prefix' => substr($rawKey, 0, 20),
        'name' => 'Secret',
    ]);

    $response = $this->postJson(
        '/api/v1/payments/'.$this->payment->key.'/confirm',
        ['payment_method' => 'card'],
        secretHeaders($rawKey),
    );

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertJsonPath('data.type', 'payments')
        ->assertJsonPath('data.id', $this->payment->key);

    $attrs = $response->json('data.attributes');
    expect($attrs)->toHaveKeys(['status', 'amount', 'currency', 'client_secret']);
    expect($attrs['status'])->toBe('requires_customer_action');
});
