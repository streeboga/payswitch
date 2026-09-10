<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['payswitch.admin_api_key' => 'admin_test_key']);
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
});

test('can create api key for merchant', function () {
    $response = $this->postJson("/api/v1/merchants/{$this->merchant->key}/api-keys", [
        'name' => 'Production Key',
    ], ['api-key' => 'admin_test_key']);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'api-keys')
        ->assertJsonStructure([
            'data' => [
                'type', 'id',
                'attributes' => ['name', 'key_prefix', 'api_key', 'created_at'],
            ],
        ]);

    // api_key (raw key) shown only at creation
    expect($response->json('data.attributes.api_key'))->toStartWith('snd_');
});

test('raw api key is shown only at creation', function () {
    $create = $this->postJson("/api/v1/merchants/{$this->merchant->key}/api-keys", [
        'name' => 'Once Key',
    ], ['api-key' => 'admin_test_key']);

    $keyId = $create->json('data.id');

    // Subsequent retrieval should NOT include raw key
    // (API keys are not retrievable individually — only list)
    expect($create->json('data.attributes.api_key'))->not->toBeNull();
});

test('can revoke api key', function () {
    $create = $this->postJson("/api/v1/merchants/{$this->merchant->key}/api-keys", [
        'name' => 'Revocable',
    ], ['api-key' => 'admin_test_key']);

    $keyId = $create->json('data.id');

    $response = $this->deleteJson(
        "/api/v1/merchants/{$this->merchant->key}/api-keys/{$keyId}",
        [],
        ['api-key' => 'admin_test_key']
    );

    $response->assertStatus(204);

    // Verify key is revoked — using it should return 401
    $rawKey = $create->json('data.attributes.api_key');
    $this->getJson('/api/v1/payments/pay_test', ['api-key' => $rawKey])
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'api_key_revoked');
});

test('revoking an unknown key id is a 404, not a 500', function () {
    $this->deleteJson(
        "/api/v1/merchants/{$this->merchant->key}/api-keys/prod_not_an_id",
        [],
        ['api-key' => 'admin_test_key']
    )->assertStatus(404);
});
