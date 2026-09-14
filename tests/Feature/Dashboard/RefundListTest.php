<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\Refund;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    UserRole::create(['user_id' => $this->user->id, 'organization_id' => $org->id, 'role' => 'admin']);
    $this->headers = ['X-Merchant-Key' => $this->merchant->key];

    $this->payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 10000,
        'net_amount' => 9700,
        'amount_capturable' => 0,
        'amount_received' => 10000,
        'currency' => 'USD',
        'status' => PaymentStatus::Succeeded,
        'capture_method' => 'automatic',
        'authentication_type' => 'no_three_ds',
        'session_expiry' => 900,
    ]);
});

function createTestRefund(object $context, array $overrides = []): Refund
{
    return Refund::create(array_merge([
        'payment_intent_id' => $context->payment->id,
        'merchant_account_id' => $context->merchant->id,
        'amount' => 5000,
        'currency' => 'USD',
        'status' => RefundStatus::Succeeded,
    ], $overrides));
}

test('refunds list returns paginated json:api response', function () {
    createTestRefund($this);
    createTestRefund($this, ['amount' => 3000]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/refunds', $this->headers);

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [['type', 'id', 'attributes']],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ])
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.type', 'refunds');
});

test('refunds list filters by status', function () {
    createTestRefund($this);
    createTestRefund($this, ['status' => RefundStatus::Failed]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/refunds?filter[status]=succeeded', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.attributes.status', 'succeeded');
});

test('refunds list filters by date range', function () {
    $recent = createTestRefund($this);
    $recent->forceFill(['created_at' => now()->subDays(5)])->save();

    $old = createTestRefund($this);
    $old->forceFill(['created_at' => now()->subDays(60)])->save();

    $from = now()->subDays(7)->toDateString();
    $to = now()->toDateString();

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/refunds?filter[from]={$from}&filter[to]={$to}", $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1);
});

test('refunds list supports search', function () {
    createTestRefund($this, ['reason' => 'Customer requested']);
    createTestRefund($this, ['reason' => 'Fraud']);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/refunds?filter[search]=Customer', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1);
});

test('refunds list supports sorting', function () {
    createTestRefund($this, ['amount' => 1000]);
    createTestRefund($this, ['amount' => 5000]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/refunds?sort=-amount', $this->headers);

    $response->assertOk();
    expect($response->json('data.0.attributes.amount'))->toBeGreaterThan($response->json('data.1.attributes.amount'));
});

test('refunds list scoped to merchant', function () {
    createTestRefund($this);

    $otherOrg = Organization::create(['name' => 'Other']);
    $otherMerchant = MerchantAccount::create(['org_id' => $otherOrg->id, 'name' => 'Other']);
    $otherPayment = PaymentIntent::create([
        'merchant_account_id' => $otherMerchant->id,
        'amount' => 10000,
        'net_amount' => 9700,
        'amount_capturable' => 0,
        'amount_received' => 10000,
        'currency' => 'USD',
        'status' => PaymentStatus::Succeeded,
        'capture_method' => 'automatic',
        'authentication_type' => 'no_three_ds',
        'session_expiry' => 900,
    ]);
    Refund::create([
        'payment_intent_id' => $otherPayment->id,
        'merchant_account_id' => $otherMerchant->id,
        'amount' => 9999,
        'currency' => 'USD',
        'status' => RefundStatus::Succeeded,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/refunds', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1);
});

test('refunds list requires authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/refunds', $this->headers);

    $response->assertUnauthorized();
});

test('refunds list requires merchant context', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/refunds');

    $response->assertNotFound();
});
