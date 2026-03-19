<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserRole;
use App\Services\ConnectorHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentAttempt;
use Streeboga\PaymentData\Models\PaymentIntent;

covers(ConnectorHealthService::class);

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    UserRole::create(['user_id' => $this->user->id, 'organization_id' => $org->id, 'role' => 'admin']);
    $profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    $this->mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $profile->id,
        'connector_name' => 'stripe',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    $this->headers = ['X-Merchant-Key' => $this->merchant->key];
});

function createPaymentWithAttempt(object $ctx, string $status, array $attemptOverrides = []): PaymentAttempt
{
    $pi = PaymentIntent::create([
        'merchant_account_id' => $ctx->merchant->id,
        'amount' => 5000,
        'net_amount' => $status === 'succeeded' ? 4850 : 0,
        'amount_capturable' => 0,
        'amount_received' => $status === 'succeeded' ? 5000 : 0,
        'currency' => 'USD',
        'status' => $status === 'succeeded' ? PaymentStatus::Succeeded : PaymentStatus::Failed,
        'capture_method' => 'automatic',
        'authentication_type' => 'no_three_ds',
        'session_expiry' => now()->addMinutes(15),
    ]);

    return PaymentAttempt::create(array_merge([
        'payment_intent_id' => $pi->id,
        'connector' => $ctx->mca->connector_name,
        'status' => $status,
        'amount' => 5000,
    ], $attemptOverrides));
}

test('health endpoint returns json:api response with correct stats', function () {
    createPaymentWithAttempt($this, 'succeeded');
    createPaymentWithAttempt($this, 'succeeded');
    createPaymentWithAttempt($this, 'failed', [
        'error_code' => 'card_declined',
        'error_message' => 'Card was declined',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/connectors/{$this->mca->key}/health", $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.type', 'connector-health')
        ->assertJsonPath('data.id', $this->mca->key)
        ->assertJsonPath('data.attributes.connector_name', 'stripe')
        ->assertJsonPath('data.attributes.period', '24h')
        ->assertJsonPath('data.attributes.total_attempts', 3)
        ->assertJsonPath('data.attributes.success_count', 2)
        ->assertJsonPath('data.attributes.error_count', 1)
        ->assertJsonPath('data.attributes.success_rate', 66.67)
        ->assertJsonPath('data.attributes.error_rate', 33.33);
});

test('health endpoint accepts period filter', function () {
    // Create an attempt 3 days ago (outside 24h, inside 7d)
    $attempt = createPaymentWithAttempt($this, 'succeeded');
    $attempt->forceFill(['created_at' => now()->subDays(3)])->save();
    $attempt->paymentIntent->forceFill(['created_at' => now()->subDays(3)])->save();

    // 24h should show 0
    $response24h = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/connectors/{$this->mca->key}/health?filter[period]=24h", $this->headers);

    $response24h->assertOk()
        ->assertJsonPath('data.attributes.total_attempts', 0);

    // 7d should show 1
    $response7d = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/connectors/{$this->mca->key}/health?filter[period]=7d", $this->headers);

    $response7d->assertOk()
        ->assertJsonPath('data.attributes.total_attempts', 1)
        ->assertJsonPath('data.attributes.success_count', 1)
        ->assertJsonPath('data.attributes.success_rate', 100);
});

test('health endpoint with no attempts returns zeros', function () {
    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/connectors/{$this->mca->key}/health", $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.attributes.total_attempts', 0)
        ->assertJsonPath('data.attributes.success_count', 0)
        ->assertJsonPath('data.attributes.error_count', 0)
        ->assertJsonPath('data.attributes.success_rate', 0)
        ->assertJsonPath('data.attributes.error_rate', 0);
});

test('errors endpoint returns error breakdown', function () {
    createPaymentWithAttempt($this, 'failed', [
        'error_code' => 'card_declined',
        'error_message' => 'Card was declined',
    ]);
    createPaymentWithAttempt($this, 'failed', [
        'error_code' => 'card_declined',
        'error_message' => 'Card was declined',
    ]);
    createPaymentWithAttempt($this, 'failed', [
        'error_code' => 'insufficient_funds',
        'error_message' => 'Insufficient funds',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/connectors/{$this->mca->key}/health/errors", $this->headers);

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.type', 'connector-errors')
        ->assertJsonPath('data.0.id', '1')
        ->assertJsonPath('data.0.attributes.code', 'card_declined')
        ->assertJsonPath('data.0.attributes.message', 'Card was declined')
        ->assertJsonPath('data.0.attributes.count', 2)
        ->assertJsonPath('data.1.attributes.code', 'insufficient_funds')
        ->assertJsonPath('data.1.attributes.count', 1);
});

test('errors endpoint returns empty array when no errors', function () {
    createPaymentWithAttempt($this, 'succeeded');

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/connectors/{$this->mca->key}/health/errors", $this->headers);

    $response->assertOk()
        ->assertJsonCount(0, 'data');
});

test('health endpoint requires authentication', function () {
    $response = $this->getJson("/api/v1/dashboard/connectors/{$this->mca->key}/health", $this->headers);

    $response->assertUnauthorized();
});

test('errors endpoint requires authentication', function () {
    $response = $this->getJson("/api/v1/dashboard/connectors/{$this->mca->key}/health/errors", $this->headers);

    $response->assertUnauthorized();
});

test('health endpoint scoped to merchant', function () {
    createPaymentWithAttempt($this, 'succeeded');

    // Create another org/merchant with its own connector and attempt
    $otherOrg = Organization::create(['name' => 'Other']);
    $otherMerchant = MerchantAccount::create(['org_id' => $otherOrg->id, 'name' => 'Other']);
    $otherProfile = BusinessProfile::create(['merchant_account_id' => $otherMerchant->id]);
    $otherMca = MerchantConnectorAccount::create([
        'merchant_account_id' => $otherMerchant->id,
        'business_profile_id' => $otherProfile->id,
        'connector_name' => 'stripe',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_other'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);
    $otherPi = PaymentIntent::create([
        'merchant_account_id' => $otherMerchant->id,
        'amount' => 9999,
        'net_amount' => 9999,
        'amount_capturable' => 0,
        'amount_received' => 9999,
        'currency' => 'USD',
        'status' => PaymentStatus::Succeeded,
        'capture_method' => 'automatic',
        'authentication_type' => 'no_three_ds',
        'session_expiry' => now()->addMinutes(15),
    ]);
    PaymentAttempt::create([
        'payment_intent_id' => $otherPi->id,
        'connector' => 'stripe',
        'status' => 'succeeded',
        'amount' => 9999,
    ]);

    // Our merchant should only see 1 attempt
    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/connectors/{$this->mca->key}/health", $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.attributes.total_attempts', 1)
        ->assertJsonPath('data.attributes.success_count', 1);
});

test('health endpoint returns 404 for non-existent connector', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/connectors/mca_nonexistent/health', $this->headers);

    $response->assertNotFound();
});

test('period to hours converts correctly', function () {
    $service = app(ConnectorHealthService::class);

    expect($service->periodToHours('24h'))->toBe(24)
        ->and($service->periodToHours('7d'))->toBe(168)
        ->and($service->periodToHours('30d'))->toBe(720)
        ->and($service->periodToHours('unknown'))->toBe(24);
});
