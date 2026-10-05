<?php

declare(strict_types=1);

use App\Jobs\DeliverWebhookJob;
use App\Services\WebhookReceiverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\Refund;
use Streeboga\PaymentData\Models\WebhookEvent;

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

test('уведомление, закрывшее pending-возврат, шлёт мерчанту refund_succeeded ровно один раз', function () {
    Queue::fake();
    $refund = Refund::create([
        'payment_intent_id' => $this->payment->id,
        'merchant_account_id' => $this->merchant->id,
        'amount' => 1000,
        'currency' => 'RUB',
        'status' => RefundStatus::Pending,
        'connector' => 'test',
        'connector_refund_id' => 're_pending_1',
    ]);

    foreach ([1, 2] as $_) {
        $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
            'type' => 'refund.succeeded',
            'object' => ['id' => 're_pending_1'],
        ])->assertOk();
    }

    $event = WebhookEvent::sole();
    expect($refund->fresh()->status)->toBe(RefundStatus::Succeeded)
        ->and($event->event_type)->toBe('refund_succeeded')
        ->and($event->content['refund_id'])->toBe($refund->key)
        ->and($event->content['payment_id'])->toBe($this->payment->key);
    Queue::assertPushed(DeliverWebhookJob::class, 1);
});

test('уведомление об отказе закрывает pending-возврат в failed и шлёт refund_failed', function () {
    $refund = Refund::create([
        'payment_intent_id' => $this->payment->id,
        'merchant_account_id' => $this->merchant->id,
        'amount' => 1000,
        'currency' => 'RUB',
        'status' => RefundStatus::Pending,
        'connector' => 'test',
        'connector_refund_id' => 're_pending_2',
    ]);

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'refund.canceled',
        'object' => ['id' => 're_pending_2'],
    ])->assertOk();

    expect($refund->fresh()->status)->toBe(RefundStatus::Failed)
        ->and(WebhookEvent::sole()->event_type)->toBe('refund_failed');
});

test('возврат другого коннектора уведомлением этого не двигается', function () {
    $refund = Refund::create([
        'payment_intent_id' => $this->payment->id,
        'merchant_account_id' => $this->merchant->id,
        'amount' => 1000,
        'currency' => 'RUB',
        'status' => RefundStatus::Pending,
        'connector' => 'yookassa',
        'connector_refund_id' => 're_foreign',
    ]);

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'refund.succeeded',
        'object' => ['id' => 're_foreign'],
    ])->assertOk();

    expect($refund->fresh()->status)->toBe(RefundStatus::Pending)
        ->and(WebhookEvent::count())->toBe(0);
});
