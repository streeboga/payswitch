<?php

declare(strict_types=1);

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->headers = ['X-Merchant-Key' => $this->merchant->key];
});

test('notifications list returns paginated response', function () {
    AppNotification::create(['user_id' => $this->user->id, 'type' => 'failed_payment', 'title' => 'Payment failed']);
    AppNotification::create(['user_id' => $this->user->id, 'type' => 'dispute', 'title' => 'New dispute']);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/notifications', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.type', 'notifications');
});

test('notifications filter by read status', function () {
    AppNotification::create(['user_id' => $this->user->id, 'type' => 'alert', 'title' => 'Read', 'read_at' => now()]);
    AppNotification::create(['user_id' => $this->user->id, 'type' => 'alert', 'title' => 'Unread']);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/notifications?filter[read]=false', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1);
});

test('mark notification as read', function () {
    $n = AppNotification::create(['user_id' => $this->user->id, 'type' => 'alert', 'title' => 'Test']);

    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/dashboard/notifications/{$n->key}/read", [], $this->headers);

    $response->assertOk();
    expect($n->fresh()->read_at)->not->toBeNull();
});

test('mark all read', function () {
    AppNotification::create(['user_id' => $this->user->id, 'type' => 'alert', 'title' => 'A']);
    AppNotification::create(['user_id' => $this->user->id, 'type' => 'alert', 'title' => 'B']);

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/notifications/mark-all-read', [], $this->headers);

    $response->assertOk();
    expect(AppNotification::whereNull('read_at')->count())->toBe(0);
});

test('delete notification', function () {
    $n = AppNotification::create(['user_id' => $this->user->id, 'type' => 'alert', 'title' => 'Test']);

    $response = $this->actingAs($this->user)
        ->deleteJson("/api/v1/dashboard/notifications/{$n->key}", [], $this->headers);

    $response->assertNoContent();
    expect(AppNotification::withTrashed()->find($n->id)->deleted_at)->not->toBeNull();
});

test('notifications require authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/notifications', $this->headers);
    $response->assertUnauthorized();
});
