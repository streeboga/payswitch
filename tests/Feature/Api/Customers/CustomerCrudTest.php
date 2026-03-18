<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Support\IdGenerator;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);

    $this->rawKey = IdGenerator::apiKey('sandbox');
    ApiKey::create([
        'merchant_account_id' => $this->merchant->id,
        'key_hash' => bcrypt($this->rawKey),
        'key_prefix' => substr($this->rawKey, 0, 10),
        'name' => 'Test',
    ]);
});

test('can create customer', function () {
    $response = $this->postJson('/api/v1/customers', [
        'data' => [
            'type' => 'customers',
            'attributes' => [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'phone' => '+14155551234',
                'metadata' => ['tier' => 'premium'],
            ],
        ],
    ], ['api-key' => $this->rawKey]);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'customers')
        ->assertJsonPath('data.attributes.name', 'John Doe')
        ->assertJsonPath('data.attributes.email', 'john@example.com')
        ->assertJsonPath('data.attributes.metadata.tier', 'premium');

    expect($response->json('data.id'))->toStartWith('cus_');
});

test('can create customer with custom id', function () {
    $response = $this->postJson('/api/v1/customers', [
        'data' => [
            'type' => 'customers',
            'id' => 'my_custom_id_123',
            'attributes' => [
                'name' => 'Custom ID User',
                'email' => 'custom@example.com',
            ],
        ],
    ], ['api-key' => $this->rawKey]);

    $response->assertStatus(201)
        ->assertJsonPath('data.id', 'my_custom_id_123');
});

test('can retrieve customer', function () {
    $create = $this->postJson('/api/v1/customers', [
        'data' => [
            'type' => 'customers',
            'attributes' => ['name' => 'Jane', 'email' => 'jane@example.com'],
        ],
    ], ['api-key' => $this->rawKey]);

    $customerId = $create->json('data.id');

    $response = $this->getJson("/api/v1/customers/{$customerId}", ['api-key' => $this->rawKey]);

    $response->assertOk()
        ->assertJsonPath('data.id', $customerId)
        ->assertJsonPath('data.attributes.name', 'Jane');
});

test('can update customer via PATCH', function () {
    $create = $this->postJson('/api/v1/customers', [
        'data' => [
            'type' => 'customers',
            'attributes' => ['name' => 'Old Name', 'email' => 'old@example.com'],
        ],
    ], ['api-key' => $this->rawKey]);

    $customerId = $create->json('data.id');

    $response = $this->patchJson("/api/v1/customers/{$customerId}", [
        'data' => [
            'type' => 'customers',
            'id' => $customerId,
            'attributes' => ['name' => 'New Name'],
        ],
    ], ['api-key' => $this->rawKey]);

    $response->assertOk()
        ->assertJsonPath('data.attributes.name', 'New Name')
        ->assertJsonPath('data.attributes.email', 'old@example.com'); // unchanged
});

test('can delete customer', function () {
    $create = $this->postJson('/api/v1/customers', [
        'data' => [
            'type' => 'customers',
            'attributes' => ['name' => 'Delete Me', 'email' => 'del@example.com'],
        ],
    ], ['api-key' => $this->rawKey]);

    $customerId = $create->json('data.id');

    $this->deleteJson("/api/v1/customers/{$customerId}", [], ['api-key' => $this->rawKey])
        ->assertStatus(204);

    $this->getJson("/api/v1/customers/{$customerId}", ['api-key' => $this->rawKey])
        ->assertStatus(404);
});

test('customer is scoped to authenticated merchant', function () {
    $this->postJson('/api/v1/customers', [
        'data' => [
            'type' => 'customers',
            'attributes' => ['name' => 'Scoped', 'email' => 's@example.com'],
        ],
    ], ['api-key' => $this->rawKey]);

    // Different merchant
    $org2 = Organization::create(['name' => 'Org2']);
    $merchant2 = MerchantAccount::create(['org_id' => $org2->id, 'name' => 'M2']);
    $rawKey2 = IdGenerator::apiKey('sandbox');
    ApiKey::create([
        'merchant_account_id' => $merchant2->id,
        'key_hash' => bcrypt($rawKey2),
        'key_prefix' => substr($rawKey2, 0, 10),
        'name' => 'Other',
    ]);

    // Other merchant should see 0 customers (or 404 for specific)
    $response = $this->getJson('/api/v1/customers', ['api-key' => $rawKey2]);
    $response->assertOk()
        ->assertJsonCount(0, 'data');
});
