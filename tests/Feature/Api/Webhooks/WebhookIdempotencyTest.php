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
    $this->payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000,
        'currency' => 'RUB',
        'status' => PaymentStatus::Succeeded,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
        'amount_received' => 5000,
    ]);
});

test('duplicate refund webhook does not update already succeeded refund', function () {
    $refund = Refund::create([
        'payment_intent_id' => $this->payment->id,
        'merchant_account_id' => $this->merchant->id,
        'amount' => 1000,
        'currency' => 'RUB',
        'status' => RefundStatus::Succeeded,
        'connector' => 'test',
        'connector_refund_id' => 're_test_123',
    ]);

    $updatedAt = $refund->updated_at;

    // Send refund.succeeded webhook — should be skipped
    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'refund.succeeded',
        'object' => ['id' => 're_test_123'],
    ])->assertOk();

    $fresh = $refund->fresh();
    expect($fresh->status)->toBe(RefundStatus::Succeeded)
        ->and($fresh->updated_at->toDateTimeString())->toBe($updatedAt->toDateTimeString());
});

test('duplicate refund webhook does not update already failed refund', function () {
    $refund = Refund::create([
        'payment_intent_id' => $this->payment->id,
        'merchant_account_id' => $this->merchant->id,
        'amount' => 1000,
        'currency' => 'RUB',
        'status' => RefundStatus::Failed,
        'connector' => 'test',
        'connector_refund_id' => 're_test_456',
    ]);

    $updatedAt = $refund->updated_at;

    // Send refund.succeeded webhook — should be skipped because refund is already terminal
    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'refund.succeeded',
        'object' => ['id' => 're_test_456'],
    ])->assertOk();

    $fresh = $refund->fresh();
    expect($fresh->status)->toBe(RefundStatus::Failed)
        ->and($fresh->updated_at->toDateTimeString())->toBe($updatedAt->toDateTimeString());
});

test('refund webhook still processes pending refund', function () {
    $refund = Refund::create([
        'payment_intent_id' => $this->payment->id,
        'merchant_account_id' => $this->merchant->id,
        'amount' => 1000,
        'currency' => 'RUB',
        'status' => RefundStatus::Pending,
        'connector' => 'test',
        'connector_refund_id' => 're_test_789',
    ]);

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'refund.succeeded',
        'object' => ['id' => 're_test_789'],
    ])->assertOk();

    expect($refund->fresh()->status)->toBe(RefundStatus::Succeeded);
});
