<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserRole;
use App\Services\TestPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;

covers(TestPaymentService::class);

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $org = Organization::create(['name' => 'TestOrg']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'TestMerchant']);
    UserRole::create(['user_id' => $this->user->id, 'organization_id' => $org->id, 'role' => 'admin']);
    $this->headers = ['X-Merchant-Key' => $this->merchant->key];

    $profile = BusinessProfile::create([
        'merchant_account_id' => $this->merchant->id,
    ]);

    $this->connector = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $profile->id,
        'connector_name' => 'test',
        'connector_type' => 'payment_processor',
        'connector_account_details' => [],
        'test_mode' => true,
        'disabled' => false,
    ]);
});

test('test payment creation returns 201 with correct payment data', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/test-payments', [
            'amount' => 5000,
            'currency' => 'USD',
            'connector_name' => 'test',
        ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'payments')
        ->assertJsonPath('data.attributes.amount', 5000)
        ->assertJsonPath('data.attributes.currency', 'USD')
        ->assertJsonPath('data.attributes.status', 'succeeded')
        ->assertJsonPath('data.attributes.connector', 'test')
        ->assertJsonPath('data.attributes.capture_method', 'automatic')
        ->assertJsonPath('data.attributes.amount_received', 5000);
});

test('test payment with default card succeeds', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/test-payments', [
            'amount' => 1000,
            'connector_name' => 'test',
        ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.attributes.status', 'succeeded')
        ->assertJsonPath('data.attributes.amount', 1000)
        ->assertJsonPath('data.attributes.currency', 'USD')
        ->assertJsonPath('data.attributes.connector', 'test');
});

test('test payment with declined card returns failed status', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/test-payments', [
            'amount' => 2000,
            'currency' => 'EUR',
            'connector_name' => 'test',
            'card_number' => '4000000000000002',
        ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.attributes.status', 'failed')
        ->assertJsonPath('data.attributes.amount', 2000)
        ->assertJsonPath('data.attributes.currency', 'EUR')
        ->assertJsonPath('data.attributes.connector', 'test')
        ->assertJsonPath('data.attributes.error_code', 'card_declined')
        ->assertJsonPath('data.attributes.error_message', 'Your card was declined');
});

test('test payment with manual capture returns requires_capture status', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/test-payments', [
            'amount' => 3000,
            'currency' => 'USD',
            'capture_method' => 'manual',
            'connector_name' => 'test',
        ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.attributes.status', 'requires_capture')
        ->assertJsonPath('data.attributes.amount', 3000)
        ->assertJsonPath('data.attributes.capture_method', 'manual')
        ->assertJsonPath('data.attributes.connector', 'test');
});

test('test payment requires authentication', function () {
    $response = $this->postJson('/api/v1/dashboard/test-payments', [
        'amount' => 1000,
        'connector_name' => 'test',
    ], $this->headers);

    $response->assertUnauthorized();
});

test('test payment validation requires amount', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/test-payments', [
            'currency' => 'USD',
            'connector_name' => 'test',
        ], $this->headers);

    $response->assertUnprocessable()
        ->assertJsonFragment([
            'source' => ['pointer' => '/amount'],
        ]);
});

test('test payment validation rejects zero amount', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/test-payments', [
            'amount' => 0,
            'connector_name' => 'test',
        ], $this->headers);

    $response->assertUnprocessable()
        ->assertJsonFragment([
            'source' => ['pointer' => '/amount'],
            'detail' => 'The amount field must be at least 1.',
        ]);
});

test('test payment validation rejects invalid currency', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/test-payments', [
            'amount' => 1000,
            'currency' => 'INVALID',
            'connector_name' => 'test',
        ], $this->headers);

    $response->assertUnprocessable()
        ->assertJsonFragment([
            'source' => ['pointer' => '/currency'],
            'detail' => 'The currency field must be 3 characters.',
        ]);
});

test('test payment with description stores it', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/test-payments', [
            'amount' => 7500,
            'currency' => 'GBP',
            'connector_name' => 'test',
            'description' => 'Integration test payment',
        ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.attributes.amount', 7500)
        ->assertJsonPath('data.attributes.currency', 'GBP')
        ->assertJsonPath('data.attributes.description', 'Integration test payment')
        ->assertJsonPath('data.attributes.status', 'succeeded');
});

// П13: env() вне config после config:cache возвращает null — адрес панели
// брался из запасного localhost:3000.
test('create-only return_url points to configured frontend url', function () {
    config(['app.frontend_url' => 'https://panel.example.com/']);

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/test-payments/create-only', [
            'amount' => 5000,
            'currency' => 'RUB',
            'connector_name' => 'test',
        ], $this->headers)
        ->assertCreated();

    $payment = PaymentIntent::where('key', $response->json('data.id'))->firstOrFail();
    expect($payment->return_url)->toBe("https://panel.example.com/payments/{$payment->key}");
});
