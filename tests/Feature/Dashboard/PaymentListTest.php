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

function createTestPayment(object $context, array $overrides = []): PaymentIntent
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
        'session_expiry' => 900,
    ], $overrides));
}

test('payments list returns paginated json:api response', function () {
    createTestPayment($this);
    createTestPayment($this);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/payments', $this->headers);

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [['type', 'id', 'attributes']],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ])
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.type', 'payments');
});

test('payments list filters by status', function () {
    createTestPayment($this);
    createTestPayment($this, ['status' => PaymentStatus::Failed, 'amount_received' => 0, 'net_amount' => 0]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/payments?filter[status]=succeeded', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.attributes.status', 'succeeded');
});

test('payments list filters by currency', function () {
    createTestPayment($this, ['currency' => 'USD']);
    createTestPayment($this, ['currency' => 'EUR']);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/payments?filter[currency]=EUR', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.attributes.currency', 'EUR');
});

test('payments list filters by amount range', function () {
    createTestPayment($this, ['amount' => 1000, 'net_amount' => 970, 'amount_received' => 1000]);
    createTestPayment($this, ['amount' => 5000]);
    createTestPayment($this, ['amount' => 10000, 'net_amount' => 9700, 'amount_received' => 10000]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/payments?filter[amount_min]=2000&filter[amount_max]=8000', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1);
});

test('payments list filters by date range', function () {
    $recent = createTestPayment($this);
    $recent->forceFill(['created_at' => now()->subDays(5)])->save();

    $old = createTestPayment($this);
    $old->forceFill(['created_at' => now()->subDays(60)])->save();

    $from = now()->subDays(7)->toDateString();
    $to = now()->toDateString();

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/payments?filter[from]={$from}&filter[to]={$to}", $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1);
});

test('payments list supports search', function () {
    createTestPayment($this, ['description' => 'Order #12345']);
    createTestPayment($this, ['description' => 'Subscription']);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/payments?filter[search]=12345', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1);
});

test('payments list supports sorting', function () {
    createTestPayment($this, ['amount' => 1000]);
    createTestPayment($this, ['amount' => 5000]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/payments?sort=-amount', $this->headers);

    $response->assertOk();
    expect($response->json('data.0.attributes.amount'))->toBeGreaterThan($response->json('data.1.attributes.amount'));
});

test('payments list supports custom page size', function () {
    createTestPayment($this);
    createTestPayment($this);
    createTestPayment($this);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/payments?page[size]=2', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonCount(2, 'data');
});

test('payments list scoped to merchant', function () {
    createTestPayment($this);

    $otherOrg = Organization::create(['name' => 'Other']);
    $otherMerchant = MerchantAccount::create(['org_id' => $otherOrg->id, 'name' => 'Other']);
    PaymentIntent::create([
        'merchant_account_id' => $otherMerchant->id,
        'amount' => 9999,
        'net_amount' => 9999,
        'amount_capturable' => 0,
        'amount_received' => 9999,
        'currency' => 'USD',
        'status' => PaymentStatus::Succeeded,
        'capture_method' => 'automatic',
        'authentication_type' => 'no_three_ds',
        'session_expiry' => 900,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/payments', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1);
});

test('payments export returns csv', function () {
    createTestPayment($this);

    $response = $this->actingAs($this->user)
        ->get('/api/v1/dashboard/payments/export', $this->headers);

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

test('payments export csv has correct headers', function () {
    createTestPayment($this);

    $response = $this->actingAs($this->user)
        ->get('/api/v1/dashboard/payments/export', $this->headers);

    $response->assertOk();

    $csv = $response->streamedContent();
    $lines = array_filter(explode("\n", trim($csv)));
    $headers = str_getcsv($lines[0]);

    expect($headers)->toBe(['id', 'status', 'amount', 'currency', 'connector', 'description', 'error_code', 'created_at']);
});

test('payments export csv contains payment data', function () {
    $payment = createTestPayment($this, [
        'currency' => 'EUR',
        'description' => 'Test export payment',
        'connector' => 'stripe',
    ]);

    $response = $this->actingAs($this->user)
        ->get('/api/v1/dashboard/payments/export', $this->headers);

    $response->assertOk();

    $csv = $response->streamedContent();
    $lines = array_filter(explode("\n", trim($csv)));

    expect($lines)->toHaveCount(2); // header + 1 data row

    $row = str_getcsv($lines[1]);
    expect($row[0])->toBe($payment->key);
    expect($row[1])->toBe('succeeded');
    expect($row[2])->toBe('5000');
    expect($row[3])->toBe('EUR');
    expect($row[4])->toBe('stripe');
    expect($row[5])->toBe('Test export payment');
});

test('payments export requires authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/payments/export', $this->headers);

    $response->assertUnauthorized();
});

test('payments list requires authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/payments', $this->headers);

    $response->assertUnauthorized();
});

test('payments list requires merchant context', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/payments');

    $response->assertNotFound();
});
