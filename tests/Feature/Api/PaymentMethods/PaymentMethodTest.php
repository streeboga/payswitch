<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\Customer;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Support\IdGenerator;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->customer = Customer::create([
        'merchant_account_id' => $this->merchant->id,
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);

    $this->rawKey = IdGenerator::apiKey('sandbox');
    ApiKey::create([
        'merchant_account_id' => $this->merchant->id,
        'key_hash' => bcrypt($this->rawKey),
        'key_prefix' => substr($this->rawKey, 0, 20),
        'name' => 'Test',
    ]);
});

test('can save a payment method for customer', function () {
    $response = $this->postJson("/api/v1/customers/{$this->customer->key}/payment-methods", [
        'data' => [
            'type' => 'payment-methods',
            'attributes' => [
                'type' => 'card',
                'card_number' => '4242424242424242',
                'card_exp_month' => '12',
                'card_exp_year' => '2030',
                'card_holder_name' => 'John Doe',
                'connector_name' => 'test',
            ],
        ],
    ], ['api-key' => $this->rawKey]);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'payment-methods')
        ->assertJsonPath('data.attributes.card_last4', '4242')
        ->assertJsonPath('data.attributes.card_brand', 'visa')
        ->assertJsonPath('data.attributes.type', 'card');

    expect($response->json('data.id'))->toStartWith('pm_');
    // Must NOT contain full card number in response
    expect(json_encode($response->json()))->not->toContain('4242424242424242');
});

test('can list payment methods for customer', function () {
    // Create 2 payment methods
    for ($i = 0; $i < 2; $i++) {
        $this->postJson("/api/v1/customers/{$this->customer->key}/payment-methods", [
            'data' => ['type' => 'payment-methods', 'attributes' => [
                'type' => 'card', 'card_number' => '4242424242424242',
                'card_exp_month' => '12', 'card_exp_year' => '2030',
                'card_holder_name' => 'John', 'connector_name' => 'test',
            ]],
        ], ['api-key' => $this->rawKey]);
    }

    $response = $this->getJson("/api/v1/customers/{$this->customer->key}/payment-methods", ['api-key' => $this->rawKey]);
    $response->assertOk()->assertJsonCount(2, 'data');
});

test('can delete payment method', function () {
    $create = $this->postJson("/api/v1/customers/{$this->customer->key}/payment-methods", [
        'data' => ['type' => 'payment-methods', 'attributes' => [
            'type' => 'card', 'card_number' => '4242424242424242',
            'card_exp_month' => '12', 'card_exp_year' => '2030',
            'card_holder_name' => 'John', 'connector_name' => 'test',
        ]],
    ], ['api-key' => $this->rawKey]);
    $pmKey = $create->json('data.id');

    $this->deleteJson("/api/v1/payment-methods/{$pmKey}", [], ['api-key' => $this->rawKey])
        ->assertStatus(204);
});

test('can set default payment method', function () {
    $create = $this->postJson("/api/v1/customers/{$this->customer->key}/payment-methods", [
        'data' => ['type' => 'payment-methods', 'attributes' => [
            'type' => 'card', 'card_number' => '4242424242424242',
            'card_exp_month' => '12', 'card_exp_year' => '2030',
            'card_holder_name' => 'John', 'connector_name' => 'test',
        ]],
    ], ['api-key' => $this->rawKey]);
    $pmKey = $create->json('data.id');

    $response = $this->postJson("/api/v1/payment-methods/{$pmKey}/default", [], ['api-key' => $this->rawKey]);
    $response->assertOk()->assertJsonPath('data.attributes.is_default', true);
});

test('card brand is detected from card number', function () {
    // Visa
    $visa = $this->postJson("/api/v1/customers/{$this->customer->key}/payment-methods", [
        'data' => ['type' => 'payment-methods', 'attributes' => [
            'type' => 'card', 'card_number' => '4242424242424242',
            'card_exp_month' => '12', 'card_exp_year' => '2030',
            'connector_name' => 'test',
        ]],
    ], ['api-key' => $this->rawKey]);
    $visa->assertJsonPath('data.attributes.card_brand', 'visa');

    // Mastercard
    $mc = $this->postJson("/api/v1/customers/{$this->customer->key}/payment-methods", [
        'data' => ['type' => 'payment-methods', 'attributes' => [
            'type' => 'card', 'card_number' => '5555555555554444',
            'card_exp_month' => '12', 'card_exp_year' => '2030',
            'connector_name' => 'test',
        ]],
    ], ['api-key' => $this->rawKey]);
    $mc->assertJsonPath('data.attributes.card_brand', 'mastercard');
});
