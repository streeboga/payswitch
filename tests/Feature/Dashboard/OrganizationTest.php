<?php

declare(strict_types=1);

use App\Models\User;
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

test('organizations require authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/organizations', $this->headers);
    $response->assertUnauthorized();
});
