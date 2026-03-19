<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserRole;
use App\Services\RoutingRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\RoutingRule;

covers(RoutingRuleService::class);

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

test('routing rule create returns 201 with correct attributes and persists to database', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/routing-rules', [
            'type' => 'priority',
            'name' => 'Primary',
            'rules' => ['connectors' => ['stripe']],
            'active' => false,
            'priority' => 5,
        ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'routing-rules')
        ->assertJsonPath('data.attributes.name', 'Primary')
        ->assertJsonPath('data.attributes.type', 'priority')
        ->assertJsonPath('data.attributes.active', false)
        ->assertJsonPath('data.attributes.priority', 5);

    $this->assertDatabaseHas('routing_rules', [
        'merchant_account_id' => $this->merchant->id,
        'name' => 'Primary',
        'type' => 'priority',
        'active' => false,
        'priority' => 5,
    ]);

    // Verify rules JSON stored correctly
    $rule = RoutingRule::where('name', 'Primary')->first();
    expect($rule)->not->toBeNull();
    expect($rule->rules)->toBe(['connectors' => ['stripe']]);
    expect($rule->type->value)->toBe('priority');
    expect($rule->active)->toBeFalse();
    expect($rule->priority)->toBe(5);
});

test('routing rule create defaults active to true and priority to 0', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/routing-rules', [
            'type' => 'rule_based',
            'name' => 'Defaults Test',
            'rules' => ['conditions' => [['field' => 'amount', 'operator' => '>', 'value' => 1000]]],
        ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.attributes.active', true)
        ->assertJsonPath('data.attributes.priority', 0)
        ->assertJsonPath('data.attributes.type', 'rule_based');

    $this->assertDatabaseHas('routing_rules', [
        'name' => 'Defaults Test',
        'active' => true,
        'priority' => 0,
        'type' => 'rule_based',
    ]);
});

test('routing rule update changes specific fields', function () {
    $rule = RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'priority',
        'name' => 'Default',
        'rules' => ['connectors' => ['stripe']],
        'active' => true,
        'priority' => 0,
    ]);

    expect($rule->name)->toBe('Default');
    expect($rule->active)->toBeTrue();
    expect($rule->priority)->toBe(0);

    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/dashboard/routing-rules/{$rule->key}", [
            'name' => 'Updated',
            'active' => false,
            'priority' => 10,
        ], $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.attributes.name', 'Updated')
        ->assertJsonPath('data.attributes.active', false)
        ->assertJsonPath('data.attributes.priority', 10);

    $rule->refresh();
    expect($rule->name)->toBe('Updated');
    expect($rule->active)->toBeFalse();
    expect($rule->priority)->toBe(10);

    // Verify old values gone, new values in DB
    $this->assertDatabaseMissing('routing_rules', [
        'id' => $rule->id,
        'name' => 'Default',
    ]);
    $this->assertDatabaseHas('routing_rules', [
        'id' => $rule->id,
        'name' => 'Updated',
        'active' => false,
        'priority' => 10,
    ]);

    // Rules JSON should remain unchanged after partial update
    expect($rule->rules)->toBe(['connectors' => ['stripe']]);
});

test('routing rule delete returns 204 and removes from database', function () {
    $rule = RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'priority',
        'name' => 'Default',
        'rules' => ['connectors' => ['stripe']],
        'active' => true,
        'priority' => 0,
    ]);
    $key = $rule->key;

    $response = $this->actingAs($this->user)
        ->deleteJson("/api/v1/dashboard/routing-rules/{$key}", [], $this->headers);

    $response->assertNoContent();

    $this->assertDatabaseMissing('routing_rules', ['key' => $key]);
});

test('routing rules require authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/routing-rules', $this->headers);

    $response->assertUnauthorized();
});

test('routing rule create with business_profile_id resolves profile key to id', function () {
    $profile = BusinessProfile::create([
        'merchant_account_id' => $this->merchant->id,
    ]);

    /** @var RoutingRuleService $service */
    $service = app(RoutingRuleService::class);

    $rule = $service->create($this->merchant->id, [
        'type' => 'priority',
        'name' => 'With Profile',
        'rules' => ['connectors' => ['stripe']],
        'business_profile_id' => $profile->key,
    ]);

    expect($rule->name)->toBe('With Profile');
    expect($rule->business_profile_id)->toBe($profile->id);

    $this->assertDatabaseHas('routing_rules', [
        'name' => 'With Profile',
        'business_profile_id' => $profile->id,
    ]);
});

test('routing rule create with priority type and connectors rules', function () {
    $rules = ['connectors' => ['stripe', 'adyen', 'checkout']];

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/routing-rules', [
            'type' => 'priority',
            'name' => 'Priority Rule',
            'rules' => $rules,
        ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.attributes.type', 'priority')
        ->assertJsonPath('data.attributes.rules.connectors', ['stripe', 'adyen', 'checkout']);
});

test('routing rule create with rule_based type', function () {
    $rules = [
        'conditions' => [
            ['field' => 'amount', 'operator' => '>', 'value' => 1000],
            ['field' => 'currency', 'operator' => '==', 'value' => 'USD'],
        ],
        'connectors' => ['stripe'],
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/routing-rules', [
            'type' => 'rule_based',
            'name' => 'Rule Based',
            'rules' => $rules,
        ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.attributes.type', 'rule_based')
        ->assertJsonPath('data.attributes.name', 'Rule Based');

    $this->assertDatabaseHas('routing_rules', [
        'name' => 'Rule Based',
        'type' => 'rule_based',
    ]);

    // Verify rules JSON stored correctly with conditions
    $rule = RoutingRule::where('name', 'Rule Based')->first();
    expect($rule->rules)->toBe($rules);
});

test('routing rule create with volume_split type', function () {
    $rules = [
        'splits' => [
            ['connector' => 'stripe', 'weight' => 70],
            ['connector' => 'adyen', 'weight' => 30],
        ],
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/routing-rules', [
            'type' => 'volume_split',
            'name' => 'Volume Split',
            'rules' => $rules,
        ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.attributes.type', 'volume_split')
        ->assertJsonPath('data.attributes.name', 'Volume Split');

    $this->assertDatabaseHas('routing_rules', [
        'name' => 'Volume Split',
        'type' => 'volume_split',
    ]);

    // Verify splits JSON stored correctly
    $rule = RoutingRule::where('name', 'Volume Split')->first();
    expect($rule->rules)->toBe($rules);
});
