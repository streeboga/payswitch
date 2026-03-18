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
    $this->org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Merchant']);
    $this->headers = ['X-Merchant-Key' => $this->merchant->key];

    // Assign admin role so CRUD operations are authorized
    UserRole::create([
        'user_id' => $this->user->id,
        'organization_id' => $this->org->id,
        'role' => 'admin',
    ]);
});

test('merchants list returns json:api response', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/merchants', $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.0.type', 'merchants');
});

test('merchant detail returns json:api resource', function () {
    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/merchants/{$this->merchant->key}", $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.type', 'merchants')
        ->assertJsonPath('data.id', $this->merchant->key);
});

test('merchant can be created', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/merchants', [
            'name' => 'New Merchant',
            'organization_id' => $this->org->key,
        ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.attributes.name', 'New Merchant');
});

test('merchant can be updated', function () {
    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/dashboard/merchants/{$this->merchant->key}", [
            'name' => 'Updated Merchant',
        ], $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.attributes.name', 'Updated Merchant');
});

test('merchant can be deleted', function () {
    $response = $this->actingAs($this->user)
        ->deleteJson("/api/v1/dashboard/merchants/{$this->merchant->key}", [], $this->headers);

    $response->assertNoContent();
    $this->assertDatabaseMissing('merchant_accounts', ['id' => $this->merchant->id]);
});

test('merchants require authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/merchants', $this->headers);
    $response->assertUnauthorized();
});
