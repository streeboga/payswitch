<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentConnectors\ConnectorFactory;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    $this->testMca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'test',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => [],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    $this->payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'amount' => 10000,
        'currency' => 'RUB',
        'status' => PaymentStatus::RequiresPaymentMethod,
        'session_expiry' => 900,
        'expires_on' => now()->addMinutes(15),
    ]);
});

test('payer with client_secret cannot choose the connector', function () {
    // Honoured, the bogus key would be a 400 connector_not_found; ignored, routing proceeds.
    $this->postJson('/api/v1/payments/'.$this->payment->key.'/confirm', [
        'client_secret' => $this->payment->client_secret,
        'payment_method' => 'card',
        'connector' => 'mca_does_not_exist',
    ], ['api-key' => $this->merchant->publishable_key])->assertOk();
});

test('test connector cannot be built when test connectors are off', function () {
    config(['payswitch.test_connectors_enabled' => false]);

    ConnectorFactory::resolve($this->testMca);
})->throws(InvalidArgumentException::class);

test('test connector builds when enabled', function () {
    config(['payswitch.test_connectors_enabled' => true]);

    expect(ConnectorFactory::resolve($this->testMca))->not->toBeNull();
});

test('app refuses to boot in production with test connectors on', function () {
    config(['payswitch.test_connectors_enabled' => true]);
    app()->detectEnvironment(fn () => 'production');

    (new AppServiceProvider(app()))->boot();
})->throws(RuntimeException::class, 'test_connectors_enabled');

test('connectable list drops test connectors when they are off', function () {
    putenv('PAYSWITCH_TEST_CONNECTORS=false');
    $_ENV['PAYSWITCH_TEST_CONNECTORS'] = 'false';
    try {
        expect((require base_path('config/payswitch.php'))['connectable'])->toBe(['cloudpayments']);
    } finally {
        putenv('PAYSWITCH_TEST_CONNECTORS');
        unset($_ENV['PAYSWITCH_TEST_CONNECTORS']);
    }
});

test('cloudpayments connector requires credentials', function () {
    config(['payswitch.admin_api_key' => 'admin_key']);

    $this->postJson("/api/v1/merchants/{$this->merchant->key}/connectors", [
        'connector_name' => 'cloudpayments',
        'connector_type' => 'fiz_operations',
        'profile_id' => $this->profile->key,
        'connector_account_details' => ['public_id' => 'pk', 'api_secret' => ''],
    ], ['api-key' => 'admin_key'])->assertStatus(422);
});
