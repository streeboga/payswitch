<?php

declare(strict_types=1);

use App\Events\PaymentStatusChanged;
use App\Listeners\LogPaymentAudit;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\WebhookEvent;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    UserRole::create(['user_id' => $this->user->id, 'organization_id' => $org->id, 'role' => 'admin']);
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
        'session_expiry' => 900,
    ]);
});

test('event logs returns paginated json:api response', function () {
    WebhookEvent::create([
        'merchant_account_id' => $this->merchant->id,
        'payment_intent_id' => $this->payment->id,
        'event_type' => 'payment.succeeded',
        'content' => [],
        'delivered' => true,
        'delivery_attempts' => 1,
    ]);

    // Аудит пишет слушатель в activity_log (spatie), а не payment_audit_log.
    (new LogPaymentAudit)->handle(new PaymentStatusChanged($this->payment, 'processing'));

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/event-logs', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 2);
});

test('event logs filters by type', function () {
    WebhookEvent::create([
        'merchant_account_id' => $this->merchant->id,
        'payment_intent_id' => $this->payment->id,
        'event_type' => 'payment.succeeded',
        'content' => [],
        'delivered' => true,
        'delivery_attempts' => 1,
    ]);

    // Аудит пишет слушатель в activity_log (spatie), а не payment_audit_log.
    (new LogPaymentAudit)->handle(new PaymentStatusChanged($this->payment, 'processing'));

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/event-logs?filter[type]=webhook', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1);
});

test('status change from activity log keeps the api format', function () {
    (new LogPaymentAudit)->handle(new PaymentStatusChanged($this->payment, 'processing'));

    $otherOrg = Organization::create(['name' => 'Other']);
    $other = MerchantAccount::create(['org_id' => $otherOrg->id, 'name' => 'Other']);
    $foreign = PaymentIntent::create([
        'merchant_account_id' => $other->id,
        'amount' => 5000,
        'currency' => 'USD',
        'status' => PaymentStatus::Succeeded,
        'capture_method' => 'automatic',
    ]);
    (new LogPaymentAudit)->handle(new PaymentStatusChanged($foreign, 'processing'));

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/event-logs?filter[type]=status_change', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.type', 'event-logs')
        ->assertJsonPath('data.0.attributes.event_type', 'status_change')
        ->assertJsonPath('data.0.attributes.action', 'status_changed')
        ->assertJsonPath('data.0.attributes.resource_id', $this->payment->key)
        ->assertJsonPath('data.0.attributes.status', 'succeeded')
        ->assertJsonPath('data.0.attributes.detail', 'processing → succeeded');
});

test('event logs require authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/event-logs', $this->headers);
    $response->assertUnauthorized();
});
