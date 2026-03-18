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

function createAnalyticsPayment(object $context, array $overrides = []): PaymentIntent
{
    return PaymentIntent::create(array_merge([
        'merchant_account_id' => $context->merchant->id,
        'amount' => 5000,
        'net_amount' => 4850,
        'amount_capturable' => 0,
        'amount_received' => 5000,
        'currency' => 'USD',
        'status' => PaymentStatus::Succeeded,
        'capture_method' => 'automatic',
        'authentication_type' => 'no_three_ds',
        'session_expiry' => now()->addMinutes(15),
    ], $overrides));
}

test('analytics overview returns aggregated data', function () {
    createAnalyticsPayment($this);
    createAnalyticsPayment($this, ['status' => PaymentStatus::Failed, 'amount_received' => 0, 'net_amount' => 0]);
    createAnalyticsPayment($this, ['amount' => 3000, 'net_amount' => 2900, 'amount_received' => 3000]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/analytics/overview', $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.type', 'analytics-overview')
        ->assertJsonPath('data.attributes.total_count', 3)
        ->assertJsonPath('data.attributes.successful_count', 2)
        ->assertJsonPath('data.attributes.failed_count', 1);
});

test('analytics charts returns daily breakdown', function () {
    createAnalyticsPayment($this);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/analytics/charts?filter[period]=30d', $this->headers);

    $response->assertOk()
        ->assertJsonStructure(['data' => [['type', 'id', 'attributes' => ['date', 'count', 'amount', 'successful', 'failed']]]]);
});

test('analytics funnel returns conversion stages', function () {
    createAnalyticsPayment($this);
    createAnalyticsPayment($this, ['status' => PaymentStatus::RequiresPaymentMethod, 'amount_received' => 0, 'net_amount' => 0]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/analytics/funnel', $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.type', 'analytics-funnel')
        ->assertJsonPath('data.attributes.created', 2)
        ->assertJsonPath('data.attributes.captured', 1);
});

test('analytics payment-methods returns breakdown', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/analytics/payment-methods', $this->headers);

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('analytics failure-reasons returns error breakdown', function () {
    createAnalyticsPayment($this, [
        'status' => PaymentStatus::Failed,
        'amount_received' => 0,
        'net_amount' => 0,
        'error_code' => 'card_declined',
        'error_message' => 'Card declined',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/analytics/failure-reasons', $this->headers);

    $response->assertOk()
        ->assertJsonStructure(['data' => [['type', 'id', 'attributes' => ['code', 'message', 'count']]]]);
});

test('analytics requires authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/analytics/overview', $this->headers);

    $response->assertUnauthorized();
});

test('analytics requires merchant context', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/analytics/overview');

    $response->assertNotFound();
});

test('analytics with custom date range', function () {
    $recent = createAnalyticsPayment($this);
    $recent->forceFill(['created_at' => now()->subDays(5)])->save();

    $old = createAnalyticsPayment($this);
    $old->forceFill(['created_at' => now()->subDays(60)])->save();

    $from = now()->subDays(7)->toDateString();
    $to = now()->toDateString();

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/analytics/overview?filter[from]={$from}&filter[to]={$to}", $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.attributes.total_count', 1);
});

test('analytics validates period parameter', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/analytics/overview?filter[period]=invalid', $this->headers);

    $response->assertStatus(422);
});
