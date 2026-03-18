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
    $this->org = $org;
    $this->headers = ['X-Merchant-Key' => $this->merchant->key];

    // Assign admin role so CRUD operations are authorized
    UserRole::create([
        'user_id' => $this->user->id,
        'organization_id' => $org->id,
        'role' => 'admin',
    ]);
});

test('organizations list returns json:api response', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/organizations', $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.0.type', 'organizations');
});

test('organization detail returns json:api resource', function () {
    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/organizations/{$this->org->key}", $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.type', 'organizations')
        ->assertJsonPath('data.id', $this->org->key);
});

test('organization merchants returns merchant list', function () {
    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/organizations/{$this->org->key}/merchants", $this->headers);

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'merchants');
});

test('organization can be created', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/organizations', ['name' => 'New Org'], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'organizations')
        ->assertJsonPath('data.attributes.name', 'New Org');
});

test('organization can be updated', function () {
    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/dashboard/organizations/{$this->org->key}", [
            'name' => 'Updated Org',
        ], $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.attributes.name', 'Updated Org');
});

test('organization update requires name', function () {
    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/dashboard/organizations/{$this->org->key}", [
            'name' => '',
        ], $this->headers);

    $response->assertUnprocessable();
});

test('organization can be deleted', function () {
    $response = $this->actingAs($this->user)
        ->deleteJson("/api/v1/dashboard/organizations/{$this->org->key}", [], $this->headers);

    $response->assertNoContent();
    $this->assertDatabaseMissing('organizations', ['id' => $this->org->id]);
});

test('organization deletion cascades to merchants', function () {
    $this->actingAs($this->user)
        ->deleteJson("/api/v1/dashboard/organizations/{$this->org->key}", [], $this->headers);

    $this->assertDatabaseMissing('merchant_accounts', ['id' => $this->merchant->id]);
});

test('organization includes merchants_count', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/organizations', $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.0.attributes.merchants_count', 1);
});

test('organizations require authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/organizations', $this->headers);
    $response->assertUnauthorized();
});
