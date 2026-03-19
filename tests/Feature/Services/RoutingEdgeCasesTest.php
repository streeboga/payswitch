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

    $this->routing = app(RoutingService::class);
});

// --- Volume Split edge cases ---

test('volume split with all zero weights falls through to auto-select', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'volume_split',
        'name' => 'Zero weights',
        'rules' => ['split' => [
            ['connector' => 'stripe', 'weight' => 0],
            ['connector' => 'cloudpayments', 'weight' => 0],
        ]],
        'active' => true,
    ]);

    $mca = $this->routing->resolve($this->merchant->id);
    expect($mca->connector_name)->toBeIn(['stripe', 'cloudpayments', 'test']);
});

test('volume split skips disabled connector in split', function () {
    MerchantConnectorAccount::where('connector_name', 'stripe')->update(['disabled' => true]);

    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'volume_split',
        'name' => 'Split with disabled',
        'rules' => ['split' => [
            ['connector' => 'stripe', 'weight' => 100],
            ['connector' => 'cloudpayments', 'weight' => 1],
        ]],
        'active' => true,
    ]);

    // Run multiple times — stripe is disabled, so cloudpayments should be selected
    // when stripe is picked by random, or fall through to auto-select
    $connectors = collect(range(1, 50))->map(
        fn () => $this->routing->resolve($this->merchant->id)->connector_name,
    );

    expect($connectors->contains('stripe'))->toBeFalse();
});

test('volume split with empty split array falls through to auto-select', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'volume_split',
        'name' => 'Empty split',
        'rules' => ['split' => []],
        'active' => true,
    ]);

    $mca = $this->routing->resolve($this->merchant->id);
    expect($mca->connector_name)->toBeIn(['stripe', 'cloudpayments', 'test']);
});

// --- Priority edge cases ---

test('priority list with all disabled connectors falls through to auto-select', function () {
    MerchantConnectorAccount::where('connector_name', 'stripe')->update(['disabled' => true]);
    MerchantConnectorAccount::where('connector_name', 'cloudpayments')->update(['disabled' => true]);

    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'priority',
        'name' => 'All disabled in list',
        'rules' => ['connectors' => ['stripe', 'cloudpayments']],
        'active' => true,
    ]);

    // Falls through the rule (both disabled), then auto-selects 'test'
    $mca = $this->routing->resolve($this->merchant->id);
    expect($mca->connector_name)->toBe('test');
});

test('priority list with empty connectors array falls through to auto-select', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'priority',
        'name' => 'Empty priority',
        'rules' => ['connectors' => []],
        'active' => true,
    ]);

    $mca = $this->routing->resolve($this->merchant->id);
    expect($mca->connector_name)->toBeIn(['stripe', 'cloudpayments', 'test']);
});

// --- Rule-based edge cases ---

test('rule-based with malformed condition (missing fields) is skipped', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'rule_based',
        'name' => 'Malformed conditions',
        'rules' => [
            'conditions' => [
                // Missing 'value' and 'connector'
                ['field' => 'currency', 'operator' => '=='],
                // Missing 'field'
                ['operator' => '==', 'value' => 'USD', 'connector' => 'stripe'],
            ],
            'default_connector' => 'test',
        ],
        'active' => true,
    ]);

    // All conditions are malformed, falls to default_connector
    $mca = $this->routing->resolve($this->merchant->id, null, null, 'USD');
    expect($mca->connector_name)->toBe('test');
});

test('rule-based with unknown operator does not match, falls to default', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'rule_based',
        'name' => 'Unknown operator',
        'rules' => [
            'conditions' => [
                ['field' => 'currency', 'operator' => '~=', 'value' => 'USD', 'connector' => 'stripe'],
            ],
            'default_connector' => 'cloudpayments',
        ],
        'active' => true,
    ]);

    $mca = $this->routing->resolve($this->merchant->id, null, null, 'USD');
    expect($mca->connector_name)->toBe('cloudpayments');
});

test('rule-based with unknown field is skipped', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'rule_based',
        'name' => 'Unknown field',
        'rules' => [
            'conditions' => [
                ['field' => 'country', 'operator' => '==', 'value' => 'US', 'connector' => 'stripe'],
            ],
            'default_connector' => 'cloudpayments',
        ],
        'active' => true,
    ]);

    $mca = $this->routing->resolve($this->merchant->id, null, null, 'USD');
    expect($mca->connector_name)->toBe('cloudpayments');
});

test('rule-based with in operator matches array value', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'rule_based',
        'name' => 'In operator',
        'rules' => [
            'conditions' => [
                ['field' => 'currency', 'operator' => 'in', 'value' => ['USD', 'EUR', 'GBP'], 'connector' => 'stripe'],
            ],
            'default_connector' => 'test',
        ],
        'active' => true,
    ]);

    $mca = $this->routing->resolve($this->merchant->id, null, null, 'EUR');
    expect($mca->connector_name)->toBe('stripe');

    $mca = $this->routing->resolve($this->merchant->id, null, null, 'RUB');
    expect($mca->connector_name)->toBe('test');
});

// --- Priority ordering ---

test('higher priority rule is evaluated first when both match', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'rule_based',
        'name' => 'Low priority',
        'rules' => [
            'conditions' => [
                ['field' => 'currency', 'operator' => '==', 'value' => 'USD', 'connector' => 'cloudpayments'],
            ],
        ],
        'active' => true,
        'priority' => 1,
    ]);

    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'rule_based',
        'name' => 'High priority',
        'rules' => [
            'conditions' => [
                ['field' => 'currency', 'operator' => '==', 'value' => 'USD', 'connector' => 'stripe'],
            ],
        ],
        'active' => true,
        'priority' => 100,
    ]);

    $mca = $this->routing->resolve($this->merchant->id, null, null, 'USD');
    expect($mca->connector_name)->toBe('stripe');
});

// --- Cross-tenant isolation ---

test('rules from another merchant are not evaluated', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $otherMerchant = MerchantAccount::create(['org_id' => $otherOrg->id, 'name' => 'Other']);
    $otherProfile = BusinessProfile::create(['merchant_account_id' => $otherMerchant->id]);

    MerchantConnectorAccount::create([
        'merchant_account_id' => $otherMerchant->id,
        'business_profile_id' => $otherProfile->id,
        'connector_name' => 'stripe',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['api_key' => 'test'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    // Rule belongs to OTHER merchant — should route to cloudpayments
    RoutingRule::create([
        'merchant_account_id' => $otherMerchant->id,
        'type' => 'rule_based',
        'name' => 'Other merchant rule',
        'rules' => [
            'conditions' => [
                ['field' => 'currency', 'operator' => '==', 'value' => 'USD', 'connector' => 'cloudpayments'],
            ],
        ],
        'active' => true,
        'priority' => 100,
    ]);

    // Our merchant has no rules, so falls to auto-select (first active connector)
    $mca = $this->routing->resolve($this->merchant->id, null, null, 'USD');
    // Should NOT be affected by the other merchant's rule
    expect($mca->connector_name)->toBeIn(['stripe', 'cloudpayments', 'test']);

    // Verify the other merchant's rule DOES work for the other merchant
    $otherMca = $this->routing->resolve($otherMerchant->id, null, null, 'USD');
    // Other merchant only has stripe, and the rule says cloudpayments — but cloudpayments
    // doesn't exist for other merchant, so it should fall to auto-select
    expect($otherMca->connector_name)->toBe('stripe');
});
