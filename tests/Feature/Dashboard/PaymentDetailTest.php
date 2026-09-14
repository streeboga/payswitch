<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    UserRole::create(['user_id' => $this->user->id, 'organization_id' => $org->id, 'role' => 'admin']);
    $this->headers = ['X-Merchant-Key' => $this->merchant->key];
});

test('payment detail returns json:api resource', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000,
        'net_amount' => 4850,
        'amount_capturable' => 0,
        'amount_received' => 5000,
        'currency' => 'USD',
        'status' => PaymentStatus::Succeeded,
        'capture_method' => 'automatic',
        'authentication_type' => 'no_three_ds',
        'session_expiry' => 900,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/payments/{$payment->key}", $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.type', 'payments')
        ->assertJsonPath('data.id', $payment->key)
        ->assertJsonPath('data.attributes.amount', 5000)
        ->assertJsonPath('data.attributes.status', 'succeeded');
});

test('payment detail returns 404 for other merchant', function () {
    $otherOrg = Organization::create(['name' => 'Other']);
    $otherMerchant = MerchantAccount::create(['org_id' => $otherOrg->id, 'name' => 'Other']);

    $payment = PaymentIntent::create([
        'merchant_account_id' => $otherMerchant->id,
        'amount' => 5000,
        'net_amount' => 4850,
        'amount_capturable' => 0,
        'amount_received' => 5000,
        'currency' => 'USD',
        'status' => PaymentStatus::Succeeded,
        'capture_method' => 'automatic',
        'authentication_type' => 'no_three_ds',
        'session_expiry' => 900,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/payments/{$payment->key}", $this->headers);

    $response->assertNotFound();
});

test('payment detail requires authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/payments/pay_fake', $this->headers);

    $response->assertUnauthorized();
});
