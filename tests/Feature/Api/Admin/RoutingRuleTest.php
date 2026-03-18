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

test('can create priority routing rule', function () {
    $response = $this->postJson("/api/v1/merchants/{$this->merchant->key}/routing-rules", [
        'type' => 'priority',
        'name' => 'Priority routing',
        'rules' => ['connectors' => ['stripe', 'cloudpayments']],
    ], ['api-key' => 'admin_test_key']);

    $response->assertStatus(201)
        ->assertJsonPath('data.attributes.type', 'priority')
        ->assertJsonPath('data.attributes.name', 'Priority routing');
    expect($response->json('data.id'))->toStartWith('rule_');
});

test('can create rule-based routing rule', function () {
    $response = $this->postJson("/api/v1/merchants/{$this->merchant->key}/routing-rules", [
        'type' => 'rule_based',
        'name' => 'Currency routing',
        'rules' => [
            'conditions' => [
                ['field' => 'currency', 'operator' => '==', 'value' => 'RUB', 'connector' => 'cloudpayments'],
            ],
            'default_connector' => 'stripe',
        ],
    ], ['api-key' => 'admin_test_key']);

    $response->assertStatus(201);
});

test('rejects invalid routing rule type', function () {
    $response = $this->postJson("/api/v1/merchants/{$this->merchant->key}/routing-rules", [
        'type' => 'invalid_type',
        'name' => 'Bad rule',
        'rules' => [],
    ], ['api-key' => 'admin_test_key']);

    $response->assertStatus(422);
});

test('can list routing rules for merchant', function () {
    $this->postJson("/api/v1/merchants/{$this->merchant->key}/routing-rules", [
        'type' => 'priority', 'name' => 'Rule 1', 'rules' => ['connectors' => ['stripe']],
    ], ['api-key' => 'admin_test_key']);

    $this->postJson("/api/v1/merchants/{$this->merchant->key}/routing-rules", [
        'type' => 'priority', 'name' => 'Rule 2', 'rules' => ['connectors' => ['test']],
    ], ['api-key' => 'admin_test_key']);

    $this->getJson("/api/v1/merchants/{$this->merchant->key}/routing-rules", ['api-key' => 'admin_test_key'])
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('can delete routing rule', function () {
    $create = $this->postJson("/api/v1/merchants/{$this->merchant->key}/routing-rules", [
        'type' => 'priority', 'name' => 'Delete me', 'rules' => ['connectors' => ['stripe']],
    ], ['api-key' => 'admin_test_key']);

    $ruleKey = $create->json('data.id');

    $this->deleteJson("/api/v1/merchants/{$this->merchant->key}/routing-rules/{$ruleKey}", [], ['api-key' => 'admin_test_key'])
        ->assertStatus(204);
});

test('can update routing rule via PATCH', function () {
    $create = $this->postJson("/api/v1/merchants/{$this->merchant->key}/routing-rules", [
        'type' => 'priority', 'name' => 'Original', 'rules' => ['connectors' => ['stripe']],
    ], ['api-key' => 'admin_test_key']);

    $ruleKey = $create->json('data.id');

    $this->patchJson("/api/v1/merchants/{$this->merchant->key}/routing-rules/{$ruleKey}", [
        'name' => 'Updated', 'active' => false,
    ], ['api-key' => 'admin_test_key'])
        ->assertOk()
        ->assertJsonPath('data.attributes.name', 'Updated')
        ->assertJsonPath('data.attributes.active', false);
});
