<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentAuditLog;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\WebhookEvent;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->headers = ['X-Merchant-Key' => $this->merchant->key];

    $this->payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000,
        'net_amount' => 4850,
        'amount_capturable' => 0,
        'amount_received' => 5000,
        'currency' => 'USD',
        'status' => PaymentStatus::Succeeded,
        'capture_method' => 'automatic',
        'authentication_type' => 'no_three_ds',
        'session_expiry' => now()->addMinutes(15),
    ]);
});

test('event logs returns paginated json:api response', function () {
    WebhookEvent::create([
        'merchant_account_id' => $this->merchant->id,
        'event_type' => 'payment.succeeded',
        'content' => [],
        'delivered' => true,
        'delivery_attempts' => 1,
    ]);

    PaymentAuditLog::create([
        'payment_intent_id' => $this->payment->id,
        'merchant_account_id' => $this->merchant->id,
        'action' => 'status_changed',
        'previous_status' => 'processing',
        'new_status' => 'succeeded',
        'actor' => 'system',
        'created_at' => now(),
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/event-logs', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 2);
});

test('event logs filters by type', function () {
    WebhookEvent::create([
        'merchant_account_id' => $this->merchant->id,
        'event_type' => 'payment.succeeded',
        'content' => [],
        'delivered' => true,
        'delivery_attempts' => 1,
    ]);

    PaymentAuditLog::create([
        'payment_intent_id' => $this->payment->id,
        'merchant_account_id' => $this->merchant->id,
        'action' => 'status_changed',
        'previous_status' => 'processing',
        'new_status' => 'succeeded',
        'actor' => 'system',
        'created_at' => now(),
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/event-logs?filter[type]=webhook', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1);
});

test('event logs require authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/event-logs', $this->headers);
    $response->assertUnauthorized();
});
