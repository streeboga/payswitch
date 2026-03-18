<?php

declare(strict_types=1);

use App\Services\RoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\RoutingRule;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    foreach (['stripe', 'cloudpayments', 'test'] as $name) {
        MerchantConnectorAccount::create([
            'merchant_account_id' => $this->merchant->id,
            'business_profile_id' => $profile->id,
            'connector_name' => $name,
            'connector_type' => 'fiz_operations',
            'connector_account_details' => ['api_key' => 'test'],
            'payment_methods_enabled' => [['payment_method' => 'card']],
            'test_mode' => true,
        ]);
    }

    $this->routing = new RoutingService;
});

test('rule-based routing selects connector by currency', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'rule_based',
        'name' => 'Currency routing',
        'rules' => [
            'conditions' => [
                ['field' => 'currency', 'operator' => '==', 'value' => 'RUB', 'connector' => 'cloudpayments'],
                ['field' => 'currency', 'operator' => '==', 'value' => 'USD', 'connector' => 'stripe'],
            ],
            'default_connector' => 'test',
        ],
        'active' => true,
        'priority' => 10,
    ]);

    $mca = $this->routing->resolve($this->merchant->id, null, null, 'RUB');
    expect($mca->connector_name)->toBe('cloudpayments');

    $mca = $this->routing->resolve($this->merchant->id, null, null, 'USD');
    expect($mca->connector_name)->toBe('stripe');

    $mca = $this->routing->resolve($this->merchant->id, null, null, 'EUR');
    expect($mca->connector_name)->toBe('test');
});

test('rule-based routing selects by amount threshold', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'rule_based',
        'name' => 'Amount routing',
        'rules' => [
            'conditions' => [
                ['field' => 'amount', 'operator' => '>', 'value' => 100000, 'connector' => 'stripe'],
            ],
            'default_connector' => 'cloudpayments',
        ],
        'active' => true,
    ]);

    $mca = $this->routing->resolve($this->merchant->id, null, null, null, 200000);
    expect($mca->connector_name)->toBe('stripe');

    $mca = $this->routing->resolve($this->merchant->id, null, null, null, 50000);
    expect($mca->connector_name)->toBe('cloudpayments');
});

test('priority list routing uses first available connector', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'priority',
        'name' => 'Priority list',
        'rules' => ['connectors' => ['cloudpayments', 'stripe', 'test']],
        'active' => true,
    ]);

    $mca = $this->routing->resolve($this->merchant->id);
    expect($mca->connector_name)->toBe('cloudpayments');
});

test('priority list skips disabled connectors', function () {
    MerchantConnectorAccount::where('connector_name', 'cloudpayments')->update(['disabled' => true]);

    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'priority',
        'name' => 'Priority list',
        'rules' => ['connectors' => ['cloudpayments', 'stripe', 'test']],
        'active' => true,
    ]);

    $mca = $this->routing->resolve($this->merchant->id);
    expect($mca->connector_name)->toBe('stripe');
});

test('inactive rules are ignored', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'rule_based',
        'name' => 'Inactive rule',
        'rules' => ['conditions' => [['field' => 'currency', 'operator' => '==', 'value' => 'USD', 'connector' => 'cloudpayments']]],
        'active' => false,
    ]);

    // Without active rule, falls back to first available connector
    $mca = $this->routing->resolve($this->merchant->id, null, null, 'USD');
    expect($mca->connector_name)->not->toBe('cloudpayments');
});

test('explicit connector overrides all rules', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'rule_based',
        'name' => 'Currency rule',
        'rules' => ['conditions' => [['field' => 'currency', 'operator' => '==', 'value' => 'USD', 'connector' => 'cloudpayments']]],
        'active' => true,
    ]);

    $mca = $this->routing->resolve($this->merchant->id, 'stripe', null, 'USD');
    expect($mca->connector_name)->toBe('stripe');
});

test('volume split distributes across connectors', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'volume_split',
        'name' => 'Split routing',
        'rules' => ['split' => [
            ['connector' => 'stripe', 'weight' => 50],
            ['connector' => 'cloudpayments', 'weight' => 50],
        ]],
        'active' => true,
    ]);

    $connectors = collect(range(1, 100))->map(fn () => $this->routing->resolve($this->merchant->id)->connector_name);

    // Both connectors should be used (statistical — at least 10% each)
    expect($connectors->filter(fn ($c) => $c === 'stripe')->count())->toBeGreaterThan(10);
    expect($connectors->filter(fn ($c) => $c === 'cloudpayments')->count())->toBeGreaterThan(10);
});
