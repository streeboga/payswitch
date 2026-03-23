<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;
use Tests\Helpers\ConnectorTestData;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
});

test('cloudpayments: payment.succeeded webhook updates payment status', function () {
    $mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'cloudpayments',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['public_id' => 'pk_test', 'api_secret' => 'secret'],
        'test_mode' => true,
    ]);

    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000,
        'currency' => 'RUB',
        'status' => PaymentStatus::Processing,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
    ]);

    $fixture = ConnectorTestData::webhookFixture('cloudpayments_payment_succeeded');
    $fixture['InvoiceId'] = $payment->key;

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$mca->key}", array_merge(
        $fixture,
        ['type' => 'payment.succeeded'],
    ))->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
});

test('cloudpayments: payment.canceled webhook updates payment status', function () {
    $mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'cloudpayments',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['public_id' => 'pk_test', 'api_secret' => 'secret'],
        'test_mode' => true,
    ]);

    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000,
        'currency' => 'RUB',
        'status' => PaymentStatus::RequiresCapture,
        'capture_method' => CaptureMethod::Manual,
        'attempt_count' => 1,
    ]);

    $fixture = ConnectorTestData::webhookFixture('cloudpayments_payment_canceled');
    $fixture['InvoiceId'] = $payment->key;

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$mca->key}", array_merge(
        $fixture,
        ['type' => 'payment.canceled'],
    ))->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Cancelled);
});

test('cloudpayments: real webhook without type field uses Status fallback', function () {
    $mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'cloudpayments',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['public_id' => 'pk_test', 'api_secret' => 'secret'],
        'test_mode' => true,
    ]);

    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000,
        'currency' => 'RUB',
        'status' => PaymentStatus::RequiresCustomerAction,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
    ]);

    $fixture = ConnectorTestData::webhookFixture('cloudpayments_payment_succeeded');
    $fixture['InvoiceId'] = $payment->key;

    // Real CloudPayments "pay" notification has AuthCode (no 'type' field)
    $fixture['AuthCode'] = 'A1B2C3';
    $fixture['GatewayName'] = 'Test';

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$mca->key}", $fixture)
        ->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
});

test('cloudpayments: check notification does NOT update payment status', function () {
    $mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'cloudpayments',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['public_id' => 'pk_test', 'api_secret' => 'secret'],
        'test_mode' => true,
    ]);

    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000,
        'currency' => 'RUB',
        'status' => PaymentStatus::RequiresCustomerAction,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
    ]);

    $fixture = ConnectorTestData::webhookFixture('cloudpayments_payment_succeeded');
    $fixture['InvoiceId'] = $payment->key;
    // Check notification: Status=Completed but NO AuthCode — should not update status
    unset($fixture['AuthCode'], $fixture['GatewayName']);

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$mca->key}", $fixture)
        ->assertOk();

    // Status should NOT change — still requires_customer_action
    expect($payment->fresh()->status)->toBe(PaymentStatus::RequiresCustomerAction);
});

test('yookassa: payment.succeeded webhook updates payment status', function () {
    $mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'yookassa',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['shop_id' => '123', 'secret_key' => 'sk'],
        'test_mode' => true,
    ]);

    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000,
        'currency' => 'RUB',
        'status' => PaymentStatus::Processing,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
    ]);

    $fixture = ConnectorTestData::webhookFixture('yookassa_payment_succeeded');
    $fixture['object']['metadata']['payment_id'] = $payment->key;

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$mca->key}", array_merge(
        $fixture,
        ['type' => 'payment.succeeded'],
    ))->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
});

test('yookassa: payment.canceled webhook updates payment status', function () {
    $mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'yookassa',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['shop_id' => '123', 'secret_key' => 'sk'],
        'test_mode' => true,
    ]);

    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000,
        'currency' => 'RUB',
        'status' => PaymentStatus::RequiresCapture,
        'capture_method' => CaptureMethod::Manual,
        'attempt_count' => 1,
    ]);

    $fixture = ConnectorTestData::webhookFixture('yookassa_payment_canceled');
    $fixture['object']['metadata']['payment_id'] = $payment->key;

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$mca->key}", array_merge(
        $fixture,
        ['type' => 'payment.canceled'],
    ))->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Cancelled);
});
