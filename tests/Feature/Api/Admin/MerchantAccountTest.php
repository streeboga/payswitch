<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\Organization;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['payswitch.admin_api_key' => 'admin_test_key']);
    $this->org = Organization::create(['name' => 'Test Org']);
});

test('can create merchant account', function () {
    $response = $this->postJson('/api/v1/merchants', [
        'name' => 'Test Merchant',
        'organization_id' => $this->org->key,
    ], ['api-key' => 'admin_test_key']);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'merchants')
        ->assertJsonPath('data.attributes.name', 'Test Merchant');

    expect($response->json('data.id'))->toStartWith('merchant_');
    expect($response->json('data.attributes.publishable_key'))->toStartWith('pk_');
});

test('can retrieve merchant account by key', function () {
    $createResponse = $this->postJson('/api/v1/merchants', [
        'name' => 'Fetch Merchant',
        'organization_id' => $this->org->key,
    ], ['api-key' => 'admin_test_key']);

    $merchantKey = $createResponse->json('data.id');

    $response = $this->getJson("/api/v1/merchants/{$merchantKey}", ['api-key' => 'admin_test_key']);

    $response->assertOk()
        ->assertJsonPath('data.id', $merchantKey)
        ->assertJsonPath('data.attributes.name', 'Fetch Merchant');
});

test('cannot create merchant without organization_id', function () {
    $response = $this->postJson('/api/v1/merchants', [
        'name' => 'No Org',
    ], ['api-key' => 'admin_test_key']);

    $response->assertStatus(422);
});
