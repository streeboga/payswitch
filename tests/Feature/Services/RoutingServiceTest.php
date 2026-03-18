<?php

declare(strict_types=1);

use App\Services\RoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Exceptions\PaymentException;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    $this->stripe = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $profile->id,
        'connector_name' => 'stripe',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['api_key' => 'sk_test'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    $this->yookassa = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $profile->id,
        'connector_name' => 'yookassa',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['shop_id' => '123'],
        'payment_methods_enabled' => [['payment_method' => 'bank_transfer']],
        'test_mode' => true,
    ]);

    $this->routing = new RoutingService;
});

test('resolves explicit connector by name', function () {
    $mca = $this->routing->resolve($this->merchant->id, 'stripe');
    expect($mca->connector_name)->toBe('stripe');
});

test('resolves explicit connector yookassa', function () {
    $mca = $this->routing->resolve($this->merchant->id, 'yookassa');
    expect($mca->connector_name)->toBe('yookassa');
});

test('throws for unknown explicit connector', function () {
    $this->routing->resolve($this->merchant->id, 'nonexistent');
})->throws(PaymentException::class);

test('auto-selects by payment method', function () {
    $mca = $this->routing->resolve($this->merchant->id, null, 'bank_transfer');
    expect($mca->connector_name)->toBe('yookassa');
});

test('falls back to first active connector', function () {
    $mca = $this->routing->resolve($this->merchant->id);
    expect($mca->connector_name)->toBeIn(['stripe', 'yookassa']);
});

test('throws when no connectors available', function () {
    $org2 = Organization::create(['name' => 'Org2']);
    $merchant2 = MerchantAccount::create(['org_id' => $org2->id, 'name' => 'M2']);
    $this->routing->resolve($merchant2->id);
})->throws(PaymentException::class);

test('fallback excludes already tried connectors', function () {
    $mca = $this->routing->fallback($this->merchant->id, ['stripe']);
    expect($mca->connector_name)->toBe('yookassa');
});

test('fallback returns null when all excluded', function () {
    $mca = $this->routing->fallback($this->merchant->id, ['stripe', 'yookassa']);
    expect($mca)->toBeNull();
});

test('skips disabled connectors', function () {
    $this->stripe->update(['disabled' => true]);
    $mca = $this->routing->resolve($this->merchant->id);
    expect($mca->connector_name)->toBe('yookassa');
});
