<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\RoutingRule;

// С3: маршруты панели вне resolve.merchant и профиль по ключу — только своё.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->orgA = Organization::create(['name' => 'Org A']);
    $this->merchantA = MerchantAccount::create(['org_id' => $this->orgA->id, 'name' => 'Merchant A']);
    $this->profileA = BusinessProfile::create(['merchant_account_id' => $this->merchantA->id, 'name' => 'A']);
    $this->userA = User::factory()->create();
    UserRole::create(['user_id' => $this->userA->id, 'organization_id' => $this->orgA->id, 'role' => 'admin']);

    $this->orgB = Organization::create(['name' => 'Org B']);
    $this->merchantB = MerchantAccount::create(['org_id' => $this->orgB->id, 'name' => 'Merchant B']);
    $this->profileB = BusinessProfile::create([
        'merchant_account_id' => $this->merchantB->id,
        'name' => 'B',
        'webhook_url' => 'https://b.example.com/hook',
    ]);
    $this->userB = User::factory()->create();
    UserRole::create(['user_id' => $this->userB->id, 'organization_id' => $this->orgB->id, 'role' => 'admin']);

    $this->headersA = ['X-Merchant-Key' => $this->merchantA->key];
});

// ─── Organizations ──────────────────────────────────────────

test('organizations list contains only organizations of the user', function () {
    $this->actingAs($this->userA)
        ->getJson('/api/v1/dashboard/organizations')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->orgA->key);
});

test('foreign organization show, merchants, update and delete are forbidden', function () {
    $this->actingAs($this->userA)->getJson("/api/v1/dashboard/organizations/{$this->orgB->key}")->assertForbidden();
    $this->actingAs($this->userA)->getJson("/api/v1/dashboard/organizations/{$this->orgB->key}/merchants")->assertForbidden();
    $this->actingAs($this->userA)->patchJson("/api/v1/dashboard/organizations/{$this->orgB->key}", ['name' => 'X'])->assertForbidden();
    $this->actingAs($this->userA)->deleteJson("/api/v1/dashboard/organizations/{$this->orgB->key}")->assertForbidden();

    $this->assertDatabaseHas('organizations', ['id' => $this->orgB->id, 'name' => 'Org B']);
});

test('own organization show and merchants are allowed', function () {
    $this->actingAs($this->userA)->getJson("/api/v1/dashboard/organizations/{$this->orgA->key}")->assertOk();
    $this->actingAs($this->userA)
        ->getJson("/api/v1/dashboard/organizations/{$this->orgA->key}/merchants")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('viewer cannot update own organization', function () {
    $viewer = User::factory()->create();
    UserRole::create(['user_id' => $viewer->id, 'organization_id' => $this->orgA->id, 'role' => 'viewer']);

    $this->actingAs($viewer)->getJson("/api/v1/dashboard/organizations/{$this->orgA->key}")->assertOk();
    $this->actingAs($viewer)->patchJson("/api/v1/dashboard/organizations/{$this->orgA->key}", ['name' => 'X'])->assertForbidden();
});

test('created organization gets its creator as admin and shows up in the list', function () {
    $response = $this->actingAs($this->userA)
        ->postJson('/api/v1/dashboard/organizations', ['name' => 'Org C'])
        ->assertCreated();

    $org = Organization::where('key', $response->json('data.id'))->firstOrFail();
    expect($this->userA->roleForOrganization($org->id)?->value)->toBe('admin');

    $this->actingAs($this->userA)
        ->getJson('/api/v1/dashboard/organizations')
        ->assertJsonCount(2, 'data');
});

// ─── Merchants ──────────────────────────────────────────────

test('merchants list contains only accessible merchants', function () {
    $this->actingAs($this->userA)
        ->getJson('/api/v1/dashboard/merchants')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->merchantA->key);
});

test('foreign merchant show, update and delete are forbidden', function () {
    $this->actingAs($this->userA)->getJson("/api/v1/dashboard/merchants/{$this->merchantB->key}")->assertForbidden();
    $this->actingAs($this->userA)->patchJson("/api/v1/dashboard/merchants/{$this->merchantB->key}", ['name' => 'X'])->assertForbidden();
    $this->actingAs($this->userA)->deleteJson("/api/v1/dashboard/merchants/{$this->merchantB->key}")->assertForbidden();

    $this->actingAs($this->userA)->getJson("/api/v1/dashboard/merchants/{$this->merchantA->key}")->assertOk();
});

test('merchant can be created only in organization where user is admin', function () {
    $this->actingAs($this->userA)
        ->postJson('/api/v1/dashboard/merchants', ['name' => 'Intruder', 'organization_id' => $this->orgB->key])
        ->assertForbidden();
    $this->assertDatabaseMissing('merchant_accounts', ['name' => 'Intruder']);

    $viewer = User::factory()->create();
    UserRole::create(['user_id' => $viewer->id, 'organization_id' => $this->orgA->id, 'role' => 'viewer']);
    $this->actingAs($viewer)
        ->postJson('/api/v1/dashboard/merchants', ['name' => 'By viewer', 'organization_id' => $this->orgA->key])
        ->assertForbidden();

    $this->actingAs($this->userA)
        ->postJson('/api/v1/dashboard/merchants', ['name' => 'Own', 'organization_id' => $this->orgA->key])
        ->assertCreated();
});

// ─── Profiles by merchant key ───────────────────────────────

test('profiles of foreign merchant are forbidden', function () {
    $this->actingAs($this->userA)
        ->getJson("/api/v1/dashboard/merchants/{$this->merchantB->key}/profiles")
        ->assertForbidden();
});

test('profiles of own merchant include hash key for admin only', function () {
    $this->actingAs($this->userA)
        ->getJson("/api/v1/dashboard/merchants/{$this->merchantA->key}/profiles")
        ->assertOk()
        ->assertJsonPath('data.0.attributes.payment_response_hash_key', $this->profileA->payment_response_hash_key);

    $viewer = User::factory()->create();
    UserRole::create(['user_id' => $viewer->id, 'organization_id' => $this->orgA->id, 'role' => 'viewer']);

    $this->actingAs($viewer)
        ->getJson("/api/v1/dashboard/merchants/{$this->merchantA->key}/profiles")
        ->assertOk()
        ->assertJsonPath('data.0.attributes.payment_response_hash_key', null);
});

// ─── Profiles inside merchant context ───────────────────────

test('profile of another merchant is not found in current merchant context', function () {
    $key = $this->profileB->key;

    $this->actingAs($this->userA)->getJson("/api/v1/dashboard/profiles/{$key}", $this->headersA)->assertNotFound();
    $this->actingAs($this->userA)
        ->patchJson("/api/v1/dashboard/profiles/{$key}", ['webhook_url' => 'https://evil.example.com'], $this->headersA)
        ->assertNotFound();
    $this->actingAs($this->userA)->deleteJson("/api/v1/dashboard/profiles/{$key}", [], $this->headersA)->assertNotFound();

    $this->assertDatabaseHas('business_profiles', ['id' => $this->profileB->id, 'webhook_url' => 'https://b.example.com/hook']);
});

test('own profile can be read, updated and deleted', function () {
    $key = $this->profileA->key;

    $this->actingAs($this->userA)->getJson("/api/v1/dashboard/profiles/{$key}", $this->headersA)->assertOk();
    $this->actingAs($this->userA)
        ->patchJson("/api/v1/dashboard/profiles/{$key}", ['webhook_url' => 'https://a.example.com/hook'], $this->headersA)
        ->assertOk();
    $this->actingAs($this->userA)->deleteJson("/api/v1/dashboard/profiles/{$key}", [], $this->headersA)->assertNoContent();
});

test('connector cannot be bound to profile of another merchant', function () {
    $this->actingAs($this->userA)
        ->postJson('/api/v1/dashboard/connectors', [
            'connector_name' => 'stripe',
            'connector_type' => 'fiz_operations',
            'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test'],
            'profile_id' => $this->profileB->key,
            'test_mode' => true,
        ], $this->headersA)
        ->assertUnprocessable();

    $this->assertDatabaseMissing('merchant_connector_accounts', ['business_profile_id' => $this->profileB->id]);
});

test('routing rule cannot be bound to profile of another merchant', function () {
    config(['payswitch.admin_api_key' => 'admin_test_key']);
    $headers = ['api-key' => 'admin_test_key'];

    $this->postJson("/api/v1/merchants/{$this->merchantA->key}/routing-rules", [
        'type' => 'priority',
        'name' => 'Stolen profile',
        'rules' => ['connectors' => ['stripe']],
        'business_profile_id' => $this->profileB->key,
    ], $headers)->assertUnprocessable();

    $rule = RoutingRule::create([
        'merchant_account_id' => $this->merchantA->id,
        'type' => 'priority',
        'name' => 'Own',
        'rules' => ['connectors' => ['stripe']],
    ]);

    $this->patchJson("/api/v1/merchants/{$this->merchantA->key}/routing-rules/{$rule->key}", [
        'business_profile_id' => $this->profileB->key,
    ], $headers)->assertUnprocessable();

    expect($rule->fresh()->business_profile_id)->toBeNull();
});

// ─── Audit log ──────────────────────────────────────────────

test('audit log shows only entries of users from current merchant organization', function () {
    Activity::create(['log_name' => 'default', 'description' => 'By A', 'event' => 'created', 'causer_type' => $this->userA->getMorphClass(), 'causer_id' => $this->userA->id]);
    Activity::create(['log_name' => 'default', 'description' => 'By B', 'event' => 'created', 'causer_type' => $this->userB->getMorphClass(), 'causer_id' => $this->userB->id]);

    $this->actingAs($this->userA)
        ->getJson('/api/v1/dashboard/audit-log', $this->headersA)
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.attributes.description', 'By A');

    $csv = $this->actingAs($this->userA)->get('/api/v1/dashboard/audit-log/export', $this->headersA)->streamedContent();
    expect($csv)->toContain('By A')->not->toContain('By B');
});

test('audit log of foreign merchant is forbidden', function () {
    $this->actingAs($this->userA)
        ->getJson('/api/v1/dashboard/audit-log', ['X-Merchant-Key' => $this->merchantB->key])
        ->assertForbidden();
    $this->actingAs($this->userA)
        ->get('/api/v1/dashboard/audit-log/export', ['X-Merchant-Key' => $this->merchantB->key])
        ->assertForbidden();
});
