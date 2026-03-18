<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\WebhookEvent;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    UserRole::create(['user_id' => $this->user->id, 'organization_id' => $org->id, 'role' => 'admin']);
    $this->headers = ['X-Merchant-Key' => $this->merchant->key];
});

test('webhook events list returns paginated json:api response', function () {
    WebhookEvent::create([
        'merchant_account_id' => $this->merchant->id,
        'event_type' => 'payment.succeeded',
        'content' => ['id' => 'pay_123'],
        'delivered' => true,
        'delivery_attempts' => 1,
    ]);
    WebhookEvent::create([
        'merchant_account_id' => $this->merchant->id,
        'event_type' => 'refund.succeeded',
        'content' => ['id' => 'ref_123'],
        'delivered' => false,
        'delivery_attempts' => 3,
        'last_error' => 'Connection refused',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/webhook-events', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.type', 'webhook-events');
});

test('webhook events filter by delivered status', function () {
    WebhookEvent::create([
        'merchant_account_id' => $this->merchant->id,
        'event_type' => 'payment.succeeded',
        'content' => [],
        'delivered' => true,
        'delivery_attempts' => 1,
    ]);
    WebhookEvent::create([
        'merchant_account_id' => $this->merchant->id,
        'event_type' => 'payment.failed',
        'content' => [],
        'delivered' => false,
        'delivery_attempts' => 3,
        'last_error' => 'Timeout',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/webhook-events?filter[status]=delivered', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.attributes.delivered', true);
});

test('webhook events scoped to merchant', function () {
    WebhookEvent::create([
        'merchant_account_id' => $this->merchant->id,
        'event_type' => 'payment.succeeded',
        'content' => [],
        'delivered' => true,
        'delivery_attempts' => 1,
    ]);

    $otherOrg = Organization::create(['name' => 'Other']);
    $otherMerchant = MerchantAccount::create(['org_id' => $otherOrg->id, 'name' => 'Other']);
    WebhookEvent::create([
        'merchant_account_id' => $otherMerchant->id,
        'event_type' => 'payment.succeeded',
        'content' => [],
        'delivered' => true,
        'delivery_attempts' => 1,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/webhook-events', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1);
});

test('webhook event retry dispatches job', function () {
    $event = WebhookEvent::create([
        'merchant_account_id' => $this->merchant->id,
        'event_type' => 'payment.succeeded',
        'content' => ['id' => 'pay_123'],
        'delivered' => false,
        'delivery_attempts' => 3,
        'last_error' => 'Timeout',
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("/api/v1/dashboard/webhook-events/{$event->key}/retry", [], $this->headers);

    $response->assertStatus(202)
        ->assertJsonPath('data.type', 'webhook-retries')
        ->assertJsonPath('data.attributes.status', 'dispatched');
});

test('webhook events require authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/webhook-events', $this->headers);

    $response->assertUnauthorized();
});
