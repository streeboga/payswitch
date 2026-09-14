<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserRole;
use App\Services\UserRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;

covers(UserRoleService::class);

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Admin User']);
    $this->org = Organization::create(['name' => 'Test Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Test Merchant']);
    $this->adminRole = UserRole::create([
        'user_id' => $this->user->id,
        'organization_id' => $this->org->id,
        'role' => 'admin',
    ]);
    $this->headers = ['X-Merchant-Key' => $this->merchant->key];
});

test('list user roles returns json:api response', function () {
    $secondUser = User::factory()->create(['name' => 'Operator User']);
    UserRole::create([
        'user_id' => $secondUser->id,
        'organization_id' => $this->org->id,
        'role' => 'operator',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/users', $this->headers);

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.type', 'user-roles')
        ->assertJsonPath('data.0.attributes.role', 'admin')
        ->assertJsonPath('data.0.attributes.user_id', $this->user->id)
        ->assertJsonPath('data.0.attributes.name', 'Admin User')
        ->assertJsonPath('data.1.attributes.role', 'operator')
        ->assertJsonPath('data.1.attributes.name', 'Operator User');
});

test('assign role to another user', function () {
    $newUser = User::factory()->create(['name' => 'New Member']);

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/users/roles', [
            'user_id' => $newUser->id,
            'organization_id' => $this->org->id,
            'role' => 'viewer',
        ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'user-roles')
        ->assertJsonPath('data.attributes.role', 'viewer')
        ->assertJsonPath('data.attributes.user_id', $newUser->id)
        ->assertJsonPath('data.attributes.name', 'New Member');

    $this->assertDatabaseHas('user_roles', [
        'user_id' => $newUser->id,
        'organization_id' => $this->org->id,
        'role' => 'viewer',
    ]);
});

test('update user role', function () {
    $targetUser = User::factory()->create(['name' => 'Target User']);
    $role = UserRole::create([
        'user_id' => $targetUser->id,
        'organization_id' => $this->org->id,
        'role' => 'viewer',
    ]);

    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/dashboard/users/roles/{$role->id}", [
            'role' => 'operator',
        ], $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.type', 'user-roles')
        ->assertJsonPath('data.attributes.role', 'operator')
        ->assertJsonPath('data.attributes.user_id', $targetUser->id);

    $this->assertDatabaseHas('user_roles', [
        'id' => $role->id,
        'role' => 'operator',
    ]);
});

test('remove user role', function () {
    $targetUser = User::factory()->create();
    $role = UserRole::create([
        'user_id' => $targetUser->id,
        'organization_id' => $this->org->id,
        'role' => 'operator',
    ]);

    $response = $this->actingAs($this->user)
        ->deleteJson("/api/v1/dashboard/users/roles/{$role->id}", [], $this->headers);

    $response->assertNoContent();

    $this->assertDatabaseMissing('user_roles', [
        'id' => $role->id,
    ]);
});

test('requires authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/users', $this->headers);

    $response->assertUnauthorized();
});

// С2: роли назначает только admin организации, к которой относится роль.

test('viewer cannot assign role in foreign organization', function () {
    $viewer = User::factory()->create();
    UserRole::create(['user_id' => $viewer->id, 'organization_id' => $this->org->id, 'role' => 'viewer']);
    $foreignOrg = Organization::create(['name' => 'Foreign Org']);

    $this->actingAs($viewer)
        ->postJson('/api/v1/dashboard/users/roles', [
            'user_id' => $viewer->id,
            'organization_id' => $foreignOrg->id,
            'role' => 'admin',
        ], $this->headers)
        ->assertForbidden();

    $this->assertDatabaseMissing('user_roles', ['organization_id' => $foreignOrg->id]);
});

test('viewer cannot promote himself in own organization', function () {
    $viewer = User::factory()->create();
    $role = UserRole::create(['user_id' => $viewer->id, 'organization_id' => $this->org->id, 'role' => 'viewer']);

    $this->actingAs($viewer)
        ->patchJson("/api/v1/dashboard/users/roles/{$role->id}", ['role' => 'admin'], $this->headers)
        ->assertForbidden();

    $this->actingAs($viewer)
        ->postJson('/api/v1/dashboard/users/roles', [
            'user_id' => $viewer->id,
            'organization_id' => $this->org->id,
            'role' => 'admin',
        ], $this->headers)
        ->assertForbidden();

    expect($role->fresh()->role->value)->toBe('viewer');
});

test('admin of one organization cannot update or delete role of another organization', function () {
    $foreignOrg = Organization::create(['name' => 'Foreign Org']);
    $foreignUser = User::factory()->create();
    $foreignRole = UserRole::create(['user_id' => $foreignUser->id, 'organization_id' => $foreignOrg->id, 'role' => 'viewer']);

    $this->actingAs($this->user)
        ->patchJson("/api/v1/dashboard/users/roles/{$foreignRole->id}", ['role' => 'admin'], $this->headers)
        ->assertForbidden();

    $this->actingAs($this->user)
        ->deleteJson("/api/v1/dashboard/users/roles/{$foreignRole->id}", [], $this->headers)
        ->assertForbidden();

    expect($foreignRole->fresh()?->role->value)->toBe('viewer');
});

test('last admin of organization cannot be demoted or removed', function () {
    $this->actingAs($this->user)
        ->patchJson("/api/v1/dashboard/users/roles/{$this->adminRole->id}", ['role' => 'viewer'], $this->headers)
        ->assertUnprocessable();

    $this->actingAs($this->user)
        ->deleteJson("/api/v1/dashboard/users/roles/{$this->adminRole->id}", [], $this->headers)
        ->assertUnprocessable();

    $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/users/roles', [
            'user_id' => $this->user->id,
            'organization_id' => $this->org->id,
            'role' => 'operator',
        ], $this->headers)
        ->assertUnprocessable();

    expect($this->adminRole->fresh()->role->value)->toBe('admin');
});

test('admin can be demoted when another admin remains', function () {
    $second = User::factory()->create();
    UserRole::create(['user_id' => $second->id, 'organization_id' => $this->org->id, 'role' => 'admin']);

    $this->actingAs($this->user)
        ->patchJson("/api/v1/dashboard/users/roles/{$this->adminRole->id}", ['role' => 'viewer'], $this->headers)
        ->assertOk();
});
