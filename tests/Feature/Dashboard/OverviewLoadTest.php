<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    UserRole::create(['user_id' => $this->user->id, 'organization_id' => $org->id, 'role' => 'admin']);
    $this->headers = ['X-Merchant-Key' => $this->merchant->key];
});

test('analytics overview returns valid json with no data', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/analytics/overview?period=7d', $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.type', 'analytics-overview')
        ->assertJsonPath('data.attributes.total_count', 0);
});

test('analytics charts returns valid json with no data', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/analytics/charts?period=7d', $this->headers);

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('analytics funnel returns valid json with no data', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/analytics/funnel?period=7d', $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.attributes.created', 0);
});

test('analytics payment-methods returns valid json with no data', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/analytics/payment-methods?period=7d', $this->headers);

    $response->assertOk()
        ->assertJson(['data' => []]);
});

test('analytics failure-reasons returns valid json with no data', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/analytics/failure-reasons?period=7d', $this->headers);

    $response->assertOk()
        ->assertJson(['data' => []]);
});

test('organizations returns valid json', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/organizations', $this->headers);

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('notifications unread-count returns count', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/notifications/unread-count', $this->headers);

    $response->assertOk()
        ->assertJsonPath('count', 0);
});

test('all overview endpoints respond under throttle limit', function () {
    $endpoints = [
        '/api/v1/dashboard/analytics/overview?period=7d',
        '/api/v1/dashboard/analytics/charts?period=7d',
        '/api/v1/dashboard/analytics/funnel?period=7d',
        '/api/v1/dashboard/analytics/payment-methods?period=7d',
        '/api/v1/dashboard/analytics/failure-reasons?period=7d',
        '/api/v1/dashboard/organizations',
        '/api/v1/dashboard/notifications/unread-count',
    ];

    foreach ($endpoints as $url) {
        $this->actingAs($this->user)
            ->getJson($url, $this->headers)
            ->assertOk();
    }
});
