<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\RoutingRule;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    UserRole::create(['user_id' => $this->user->id, 'organization_id' => $org->id, 'role' => 'admin']);
    $this->headers = ['X-Merchant-Key' => $this->merchant->key];
});

test('routing rules list returns json:api response', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'priority',
        'name' => 'Default',
        'rules' => ['connectors' => ['stripe']],
        'active' => true,
        'priority' => 0,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/routing-rules', $this->headers);

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'routing-rules');
});

test('routing rule create returns 201', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/routing-rules', [
            'type' => 'priority',
            'name' => 'Primary',
            'rules' => ['connectors' => ['stripe']],
        ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'routing-rules')
        ->assertJsonPath('data.attributes.name', 'Primary');
});

test('routing rule update works', function () {
    $rule = RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'priority',
        'name' => 'Default',
        'rules' => ['connectors' => ['stripe']],
        'active' => true,
        'priority' => 0,
    ]);

    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/dashboard/routing-rules/{$rule->key}", [
            'name' => 'Updated',
            'active' => false,
        ], $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.attributes.name', 'Updated')
        ->assertJsonPath('data.attributes.active', false);
});

test('routing rule delete returns 204', function () {
    $rule = RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'priority',
        'name' => 'Default',
        'rules' => ['connectors' => ['stripe']],
        'active' => true,
        'priority' => 0,
    ]);

    $response = $this->actingAs($this->user)
        ->deleteJson("/api/v1/dashboard/routing-rules/{$rule->key}", [], $this->headers);

    $response->assertNoContent();
});

test('routing rules require authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/routing-rules', $this->headers);

    $response->assertUnauthorized();
});
