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

function statusHeaders($merchant): array
{
    return ['api-key' => $merchant->publishable_key];
}

test('returns current payment status with valid client_secret', function () {
    $response = $this->getJson(
        '/api/v1/payments/'.$this->payment->key.'/status?client_secret='.$this->payment->client_secret,
        statusHeaders($this->merchant),
    );

    $response->assertOk();

    $data = $response->json('data');
    expect($data)->toHaveKeys(['status', 'amount', 'currency']);
    expect($data['status'])->toBe('requires_payment_method');
    expect($data['amount'])->toBe(10000);
    expect($data['currency'])->toBe('RUB');
});

test('returns 404 with invalid client_secret', function () {
    $response = $this->getJson(
        '/api/v1/payments/'.$this->payment->key.'/status?client_secret=invalid_secret',
        statusHeaders($this->merchant),
    );

    $response->assertStatus(403);
});

test('returns 400 without client_secret', function () {
    $response = $this->getJson(
        '/api/v1/payments/'.$this->payment->key.'/status',
        statusHeaders($this->merchant),
    );

    $response->assertStatus(403);
});

test('returns succeeded status when payment is complete', function () {
    $this->payment->update(['status' => PaymentStatus::Succeeded]);

    $response = $this->getJson(
        '/api/v1/payments/'.$this->payment->key.'/status?client_secret='.$this->payment->client_secret,
        statusHeaders($this->merchant),
    );

    $response->assertOk();
    expect($response->json('data.status'))->toBe('succeeded');
});

test('returns requires_customer_action for pending QR payments', function () {
    $this->payment->update(['status' => PaymentStatus::RequiresCustomerAction]);

    $response = $this->getJson(
        '/api/v1/payments/'.$this->payment->key.'/status?client_secret='.$this->payment->client_secret,
        statusHeaders($this->merchant),
    );

    $response->assertOk();
    expect($response->json('data.status'))->toBe('requires_customer_action');
});
