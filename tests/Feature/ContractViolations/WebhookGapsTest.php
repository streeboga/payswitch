<?php

declare(strict_types=1);

use App\Services\WebhookReceiverService;
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

covers(WebhookReceiverService::class);

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
    $this->mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $profile->id,
        'connector_name' => 'test',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['api_key' => 'test'],
        'test_mode' => true,
    ]);
});

test('refund.succeeded webhook updates refund status', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000,
        'currency' => 'USD',
        'status' => PaymentStatus::Succeeded,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
    ]);

    $refund = Refund::create([
        'payment_intent_id' => $payment->id,
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000,
        'currency' => 'USD',
        'status' => RefundStatus::Pending,
        'connector' => 'test',
        'connector_refund_id' => 'test_ref_abc123',
    ]);

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'refund.succeeded',
        'object' => [
            'id' => 'test_ref_abc123',
        ],
    ])->assertOk()
        ->assertJson(['status' => 'ok']);

    $fresh = $refund->fresh();
    expect($fresh->status)->toBe(RefundStatus::Succeeded);
});

test('payment webhook sets connector name on payment', function () {
    $this->mca->update([
        'connector_name' => 'cloudpayments',
        'connector_account_details' => ['public_id' => 'pk', 'api_secret' => 'sekret'],
    ]);

    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000,
        'currency' => 'USD',
        'status' => PaymentStatus::Processing,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
    ]);

    $body = ['type' => 'payment.succeeded', 'InvoiceId' => $payment->key];

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", $body, [
        'Content-HMAC' => base64_encode(hash_hmac('sha256', (string) json_encode($body), 'sekret', true)),
    ])->assertOk()
        ->assertJson(['status' => 'ok']);

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe(PaymentStatus::Succeeded)
        ->and($fresh->connector)->toBe('cloudpayments');
});
