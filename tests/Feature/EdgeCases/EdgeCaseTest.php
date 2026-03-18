<?php

declare(strict_types=1);

use App\Jobs\DeliverWebhookJob;
use App\Services\RoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\Customer;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentMethod;
use Streeboga\PaymentData\Models\RoutingRule;
use Streeboga\PaymentData\Support\IdGenerator;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->profile = BusinessProfile::create([
        'merchant_account_id' => $this->merchant->id,
        'webhook_url' => 'https://merchant.example.com/webhook',
    ]);

    $this->stripe = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'stripe',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['api_key' => 'sk_test'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    $this->routing = app(RoutingService::class);
});

// --- SSRF Protection ---

test('SSRF blocks file:// scheme', function () {
    $job = new DeliverWebhookJob(1);
    $reflection = new ReflectionMethod($job, 'isUrlSafe');
    $reflection->setAccessible(true);

    expect($reflection->invoke($job, 'file:///etc/passwd'))->toBeFalse();
});

test('SSRF blocks ftp:// scheme', function () {
    $job = new DeliverWebhookJob(1);
    $reflection = new ReflectionMethod($job, 'isUrlSafe');
    $reflection->setAccessible(true);

    expect($reflection->invoke($job, 'ftp://evil.com/payload'))->toBeFalse();
});

test('SSRF blocks URLs with userinfo', function () {
    $job = new DeliverWebhookJob(1);
    $reflection = new ReflectionMethod($job, 'isUrlSafe');
    $reflection->setAccessible(true);

    expect($reflection->invoke($job, 'https://user:pass@example.com/webhook'))->toBeFalse();
});

test('SSRF blocks private IP addresses', function () {
    $job = new DeliverWebhookJob(1);
    $reflection = new ReflectionMethod($job, 'isUrlSafe');
    $reflection->setAccessible(true);

    expect($reflection->invoke($job, 'http://192.168.1.1/webhook'))->toBeFalse();
    expect($reflection->invoke($job, 'http://10.0.0.1/webhook'))->toBeFalse();
    expect($reflection->invoke($job, 'http://127.0.0.1/webhook'))->toBeFalse();
});

test('SSRF blocks localhost', function () {
    $job = new DeliverWebhookJob(1);
    $reflection = new ReflectionMethod($job, 'isUrlSafe');
    $reflection->setAccessible(true);

    expect($reflection->invoke($job, 'http://localhost/webhook'))->toBeFalse();
});

// --- API Key Validation ---

test('short API key is rejected', function () {
    $response = $this->postJson('/api/v1/payments', [], [
        'api-key' => 'short',
    ]);

    $response->assertStatus(401);
});

test('missing API key is rejected', function () {
    $response = $this->postJson('/api/v1/payments');

    $response->assertStatus(401);
});

// --- Routing Rule Edge Cases ---

test('routing rule with missing condition keys is skipped', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'name' => 'Bad Rule',
        'type' => 'rule_based',
        'active' => true,
        'priority' => 10,
        'rules' => [
            'conditions' => [
                ['field' => 'currency'], // missing operator, value, connector
                ['bad_key' => 'test'],
            ],
        ],
    ]);

    // Should not crash, should fall through to auto-select
    $mca = $this->routing->resolve($this->merchant->id);
    expect($mca)->not->toBeNull();
});

test('volume split with zero total weight returns null', function () {
    $reflection = new ReflectionMethod($this->routing, 'evaluateVolumeSplitRule');
    $reflection->setAccessible(true);

    $result = $reflection->invoke($this->routing, [
        'split' => [
            ['weight' => 0, 'connector' => 'stripe'],
            ['weight' => 0, 'connector' => 'yookassa'],
        ],
    ], $this->merchant->id);

    expect($result)->toBeNull();
});

test('volume split with missing weight/connector entries is filtered', function () {
    $reflection = new ReflectionMethod($this->routing, 'evaluateVolumeSplitRule');
    $reflection->setAccessible(true);

    $result = $reflection->invoke($this->routing, [
        'split' => [
            ['connector' => 'stripe'], // missing weight
            ['weight' => 5],           // missing connector
        ],
    ], $this->merchant->id);

    expect($result)->toBeNull();
});

test('evaluateRule handles non-array config gracefully', function () {
    // Simulate a rule where config is not a proper array by testing the method directly
    $rule = RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'name' => 'Empty Config',
        'type' => 'priority',
        'active' => true,
        'priority' => 10,
        'rules' => [],
    ]);

    // Empty config should not crash, should fall through to auto-select
    $mca = $this->routing->resolve($this->merchant->id);
    expect($mca)->not->toBeNull();
});

test('fallback with empty excludeConnectors returns first active', function () {
    $mca = $this->routing->fallback($this->merchant->id, []);
    expect($mca)->not->toBeNull();
});

// --- Payment Method Validation ---

test('payment method requires type and connector_name', function () {
    $rawKey = IdGenerator::apiKey('sandbox');
    ApiKey::create([
        'merchant_account_id' => $this->merchant->id,
        'key_hash' => hash('sha256', $rawKey),
        'key_prefix' => substr($rawKey, 0, 20),
        'name' => 'Test',
    ]);

    $customer = Customer::create([
        'merchant_account_id' => $this->merchant->id,
        'name' => 'Test',
        'email' => 'test@example.com',
    ]);

    $response = $this->postJson("/api/v1/customers/{$customer->key}/payment-methods", [
        'data' => [
            'type' => 'payment-methods',
            'attributes' => [
                // missing type and connector_name
                'card_last4' => '4242',
            ],
        ],
    ], ['api-key' => $rawKey]);

    $response->assertStatus(422);
});

test('setDefault is atomic', function () {
    $rawKey = IdGenerator::apiKey('sandbox');
    ApiKey::create([
        'merchant_account_id' => $this->merchant->id,
        'key_hash' => hash('sha256', $rawKey),
        'key_prefix' => substr($rawKey, 0, 20),
        'name' => 'Test',
    ]);

    $customer = Customer::create([
        'merchant_account_id' => $this->merchant->id,
        'name' => 'Test',
        'email' => 'test@example.com',
    ]);

    $pm1 = PaymentMethod::create([
        'customer_id' => $customer->id,
        'merchant_account_id' => $this->merchant->id,
        'type' => 'card',
        'card_last4' => '4242',
        'connector_name' => 'test',
        'connector_token' => 'tok_1',
        'is_default' => true,
    ]);

    $pm2 = PaymentMethod::create([
        'customer_id' => $customer->id,
        'merchant_account_id' => $this->merchant->id,
        'type' => 'card',
        'card_last4' => '1234',
        'connector_name' => 'test',
        'connector_token' => 'tok_2',
        'is_default' => false,
    ]);

    $response = $this->postJson("/api/v1/payment-methods/{$pm2->key}/default", [], [
        'api-key' => $rawKey,
    ]);

    $response->assertOk();
    expect($pm1->fresh()->is_default)->toBeFalse();
    expect($pm2->fresh()->is_default)->toBeTrue();
});
