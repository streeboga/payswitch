<?php

declare(strict_types=1);

use App\Enums\DisputeStatus;
use App\Enums\DisputeType;
use App\Models\Dispute;
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

    $this->payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 10000, 'net_amount' => 9700, 'amount_capturable' => 0, 'amount_received' => 10000,
        'currency' => 'USD', 'status' => PaymentStatus::Succeeded,
        'capture_method' => 'automatic', 'authentication_type' => 'no_three_ds',
        'session_expiry' => now()->addMinutes(15),
    ]);
});

test('disputes list returns paginated response', function () {
    Dispute::create([
        'payment_intent_id' => $this->payment->id, 'merchant_account_id' => $this->merchant->id,
        'amount' => 10000, 'currency' => 'USD', 'type' => DisputeType::Chargeback, 'status' => DisputeStatus::Opened,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/disputes', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.type', 'disputes')
        ->assertJsonPath('data.0.attributes.status', 'opened');
});

test('dispute detail returns json:api resource', function () {
    $dispute = Dispute::create([
        'payment_intent_id' => $this->payment->id, 'merchant_account_id' => $this->merchant->id,
        'amount' => 10000, 'currency' => 'USD', 'type' => DisputeType::Chargeback, 'status' => DisputeStatus::Opened,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/disputes/{$dispute->key}", $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.type', 'disputes')
        ->assertJsonPath('data.id', $dispute->key);
});

test('disputes scoped to merchant', function () {
    Dispute::create([
        'payment_intent_id' => $this->payment->id, 'merchant_account_id' => $this->merchant->id,
        'amount' => 10000, 'currency' => 'USD', 'type' => DisputeType::Chargeback, 'status' => DisputeStatus::Opened,
    ]);

    $otherOrg = Organization::create(['name' => 'Other']);
    $otherMerchant = MerchantAccount::create(['org_id' => $otherOrg->id, 'name' => 'Other']);
    $otherPayment = PaymentIntent::create([
        'merchant_account_id' => $otherMerchant->id, 'amount' => 5000, 'net_amount' => 4850,
        'amount_capturable' => 0, 'amount_received' => 5000, 'currency' => 'USD',
        'status' => PaymentStatus::Succeeded, 'capture_method' => 'automatic',
        'authentication_type' => 'no_three_ds', 'session_expiry' => now()->addMinutes(15),
    ]);
    Dispute::create([
        'payment_intent_id' => $otherPayment->id, 'merchant_account_id' => $otherMerchant->id,
        'amount' => 5000, 'currency' => 'USD', 'type' => DisputeType::Inquiry, 'status' => DisputeStatus::Opened,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/disputes', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1);
});

test('disputes require authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/disputes', $this->headers);
    $response->assertUnauthorized();
});
