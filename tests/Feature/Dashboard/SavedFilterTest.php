<?php

declare(strict_types=1);

use App\Models\SavedFilter;
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

test('saved filters list returns json:api response', function () {
    SavedFilter::create([
        'user_id' => $this->user->id, 'table_name' => 'payments',
        'name' => 'Failed today', 'filters' => ['status' => 'failed'],
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/saved-filters', $this->headers);

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'saved-filters');
});

test('create saved filter returns 201', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/saved-filters', [
            'data' => ['type' => 'saved-filters', 'attributes' => [
                'table_name' => 'payments',
                'name' => 'My filter',
                'filters' => ['status' => 'succeeded', 'currency' => 'USD'],
            ]],
        ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'saved-filters')
        ->assertJsonPath('data.attributes.name', 'My filter');
});

test('delete saved filter returns 204', function () {
    $filter = SavedFilter::create([
        'user_id' => $this->user->id, 'table_name' => 'payments',
        'name' => 'Test', 'filters' => ['status' => 'failed'],
    ]);

    $response = $this->actingAs($this->user)
        ->deleteJson("/api/v1/dashboard/saved-filters/{$filter->id}", [], $this->headers);

    $response->assertNoContent();
});

test('saved filters scoped to user', function () {
    SavedFilter::create(['user_id' => $this->user->id, 'table_name' => 'payments', 'name' => 'Mine', 'filters' => []]);

    $other = User::factory()->create();
    SavedFilter::create(['user_id' => $other->id, 'table_name' => 'payments', 'name' => 'Theirs', 'filters' => []]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/saved-filters', $this->headers);

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

test('saved filters require authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/saved-filters', $this->headers);
    $response->assertUnauthorized();
});
