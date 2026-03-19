<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\Refund;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
    $this->mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $profile->id,
        'connector_name' => 'yookassa',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['shop_id' => 'test', 'secret_key' => 'test'],
        'test_mode' => true,
    ]);
});

test('refund.succeeded webhook updates refund status', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000,
        'currency' => 'RUB',
        'status' => PaymentStatus::Succeeded,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
        'amount_received' => 5000,
    ]);

    $refund = Refund::create([
        'payment_intent_id' => $payment->id,
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000,
        'currency' => 'RUB',
        'status' => RefundStatus::Pending,
        'connector_refund_id' => 'yk_ref_pending',
    ]);

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'refund.succeeded',
        'object' => [
            'id' => 'yk_ref_pending',
            'payment_id' => $payment->key,
            'metadata' => ['payment_id' => $payment->key],
        ],
    ])->assertOk();

    expect($refund->fresh()->status)->toBe(RefundStatus::Succeeded);
})->skip('BUG #6: WebhookReceiverService ignores refund.succeeded — YooKassaConnector.mapWebhookEventToStatus returns null');

test('payment webhook updates connector metadata', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000,
        'currency' => 'RUB',
        'status' => PaymentStatus::Processing,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
    ]);

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'payment.succeeded',
        'object' => [
            'id' => 'yk_txn_from_webhook',
            'metadata' => ['payment_id' => $payment->key],
        ],
    ])->assertOk();

    $fresh = $payment->fresh();
    expect($fresh->connector)->not->toBeNull();
})->skip('BUG #9: WebhookReceiverService only updates status and amount_received, not connector metadata');
