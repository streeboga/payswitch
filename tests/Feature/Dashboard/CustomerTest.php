<?php

declare(strict_types=1);

use App\DataTransferObjects\Customer\CreateCustomerData;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Exceptions\PaymentException;
use Streeboga\PaymentData\Models\Customer;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;

covers(CustomerService::class);

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    UserRole::create(['user_id' => $this->user->id, 'organization_id' => $org->id, 'role' => 'admin']);
    $this->headers = ['X-Merchant-Key' => $this->merchant->key];
});

test('customers list returns json:api response', function () {
    Customer::create(['merchant_account_id' => $this->merchant->id, 'email' => 'a@b.com']);
    Customer::create(['merchant_account_id' => $this->merchant->id, 'email' => 'c@d.com']);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/customers', $this->headers);

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.type', 'customers');
});

test('customers list scoped to merchant', function () {
    Customer::create(['merchant_account_id' => $this->merchant->id, 'email' => 'a@b.com']);

    $otherOrg = Organization::create(['name' => 'Other']);
    $otherMerchant = MerchantAccount::create(['org_id' => $otherOrg->id, 'name' => 'Other']);
    Customer::create(['merchant_account_id' => $otherMerchant->id, 'email' => 'x@y.com']);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/customers', $this->headers);

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

test('customer detail returns json:api resource', function () {
    $customer = Customer::create(['merchant_account_id' => $this->merchant->id, 'email' => 'a@b.com', 'name' => 'Alice']);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/customers/{$customer->key}", $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.type', 'customers')
        ->assertJsonPath('data.id', $customer->key);
});

test('customer create returns 201 and persists to database', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/customers', [
            'name' => 'New Customer',
            'email' => 'new@example.com',
        ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'customers')
        ->assertJsonPath('data.attributes.name', 'New Customer')
        ->assertJsonPath('data.attributes.email', 'new@example.com');

    $this->assertDatabaseHas('customers', [
        'name' => 'New Customer',
        'email' => 'new@example.com',
        'merchant_account_id' => $this->merchant->id,
    ]);

    // Verify exact model values in DB
    $customer = Customer::where('email', 'new@example.com')->first();
    expect($customer)->not->toBeNull();
    expect($customer->name)->toBe('New Customer');
    expect($customer->email)->toBe('new@example.com');
    expect($customer->merchant_account_id)->toBe($this->merchant->id);
});

test('customer create with phone and description persists all fields', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/customers', [
            'name' => 'Full Customer',
            'email' => 'full@example.com',
            'phone' => '+1234567890',
            'description' => 'VIP customer',
        ], $this->headers);

    $response->assertStatus(201);

    $this->assertDatabaseHas('customers', [
        'name' => 'Full Customer',
        'email' => 'full@example.com',
        'phone' => '+1234567890',
        'description' => 'VIP customer',
        'merchant_account_id' => $this->merchant->id,
    ]);
});

test('customer update changes the specified fields', function () {
    $customer = Customer::create([
        'merchant_account_id' => $this->merchant->id,
        'email' => 'old@example.com',
        'name' => 'Old Name',
    ]);

    expect($customer->name)->toBe('Old Name');
    expect($customer->email)->toBe('old@example.com');

    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/dashboard/customers/{$customer->key}", [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ], $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.attributes.name', 'Updated Name')
        ->assertJsonPath('data.attributes.email', 'updated@example.com');

    $customer->refresh();
    expect($customer->name)->toBe('Updated Name');
    expect($customer->email)->toBe('updated@example.com');

    // Verify old values are gone and new values are in DB
    $this->assertDatabaseMissing('customers', [
        'id' => $customer->id,
        'name' => 'Old Name',
    ]);
    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'name' => 'Updated Name',
        'email' => 'updated@example.com',
    ]);
});

test('customer update with empty data returns unchanged customer', function () {
    $customer = Customer::create([
        'merchant_account_id' => $this->merchant->id,
        'email' => 'keep@example.com',
        'name' => 'Keep Me',
    ]);

    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/dashboard/customers/{$customer->key}", [], $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.attributes.name', 'Keep Me')
        ->assertJsonPath('data.attributes.email', 'keep@example.com');

    $customer->refresh();
    expect($customer->name)->toBe('Keep Me');
    expect($customer->email)->toBe('keep@example.com');
});

test('customer delete returns 204 and removes from database', function () {
    $customer = Customer::create(['merchant_account_id' => $this->merchant->id, 'email' => 'del@example.com']);
    $key = $customer->key;

    $response = $this->actingAs($this->user)
        ->deleteJson("/api/v1/dashboard/customers/{$key}", [], $this->headers);

    $response->assertNoContent();

    $this->assertDatabaseMissing('customers', ['key' => $key]);
});

test('customer detail requires authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/customers/cus_fake', $this->headers);

    $response->assertUnauthorized();
});

test('customer create with custom id too long returns error', function () {
    /** @var CustomerService $service */
    $service = app(CustomerService::class);

    $dto = new CreateCustomerData(
        name: 'Test',
        email: 'test@example.com',
    );

    $longId = str_repeat('a', 65);

    expect(fn () => $service->create($dto, $this->merchant->id, $longId))
        ->toThrow(PaymentException::class, 'Customer ID must be 1-64 characters');
});

test('customer create with duplicate custom id returns error', function () {
    /** @var CustomerService $service */
    $service = app(CustomerService::class);

    $dto = new CreateCustomerData(
        name: 'First',
        email: 'first@example.com',
    );

    $service->create($dto, $this->merchant->id, 'my-custom-id');

    $dto2 = new CreateCustomerData(
        name: 'Second',
        email: 'second@example.com',
    );

    expect(fn () => $service->create($dto2, $this->merchant->id, 'my-custom-id'))
        ->toThrow(PaymentException::class, 'Customer ID already exists');
});
