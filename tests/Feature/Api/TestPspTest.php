<?php

declare(strict_types=1);

use App\Events\PaymentStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentAttempt;
use Streeboga\PaymentData\Models\PaymentIntent;

uses(RefreshDatabase::class);

function testPspPayment(MerchantAccount $merchant, array $overrides = []): PaymentIntent
{
    return PaymentIntent::create(array_merge([
        'merchant_account_id' => $merchant->id,
        'amount' => 17502,
        'currency' => 'RUB',
        'status' => PaymentStatus::RequiresCustomerAction,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
        'connector' => 'test',
        'return_url' => 'https://invoice.example.test/checkout/success',
    ], $overrides));
}

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->payment = testPspPayment($this->merchant);
});

test('подтверждение тестового платежа поднимает событие для вебхуков', function () {
    Event::fake([PaymentStatusChanged::class]);

    $this->postJson("/api/v1/test-psp/{$this->payment->key}/complete", ['action' => 'approve'])
        ->assertSuccessful();

    expect($this->payment->refresh()->status)->toBe(PaymentStatus::Succeeded);

    Event::assertDispatched(PaymentStatusChanged::class, function (PaymentStatusChanged $e) {
        return $e->payment->key === $this->payment->key
            && $e->payment->status === PaymentStatus::Succeeded;
    });
});

test('отклонение тестового платежа тоже поднимает событие', function () {
    Event::fake([PaymentStatusChanged::class]);

    $this->postJson("/api/v1/test-psp/{$this->payment->key}/complete", ['action' => 'decline'])
        ->assertSuccessful();

    expect($this->payment->refresh()->status)->toBe(PaymentStatus::Failed);
    Event::assertDispatched(PaymentStatusChanged::class);
});

test('боевой платёж тестовым симулятором не видно и не провести', function () {
    Event::fake([PaymentStatusChanged::class]);
    $live = testPspPayment($this->merchant, ['connector' => 'cloudpayments']);

    $this->getJson("/api/v1/test-psp/{$live->key}")->assertNotFound();
    $this->postJson("/api/v1/test-psp/{$live->key}/complete", ['action' => 'approve'])->assertNotFound();

    expect($live->refresh()->status)->toBe(PaymentStatus::RequiresCustomerAction);
    Event::assertNotDispatched(PaymentStatusChanged::class);
});

test('платёж без коннектора симулятору не принадлежит', function () {
    $orphan = testPspPayment($this->merchant, ['connector' => null]);

    $this->getJson("/api/v1/test-psp/{$orphan->key}")->assertNotFound();
    $this->postJson("/api/v1/test-psp/{$orphan->key}/complete", ['action' => 'approve'])->assertNotFound();

    expect($orphan->refresh()->status)->toBe(PaymentStatus::RequiresCustomerAction);
});

test('коннектор берётся из последней попытки, если у платежа он не записан', function () {
    $payment = testPspPayment($this->merchant, ['connector' => null]);
    PaymentAttempt::create(['payment_intent_id' => $payment->id, 'connector' => 'cloudpayments', 'status' => 'failed', 'amount' => 17502]);
    PaymentAttempt::create(['payment_intent_id' => $payment->id, 'connector' => 'test_sbp', 'status' => 'requires_action', 'amount' => 17502]);

    $this->postJson("/api/v1/test-psp/{$payment->key}/complete", ['action' => 'approve'])->assertSuccessful();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded);
});

test('недопустимый переход — 409 без изменений и без события', function () {
    Event::fake([PaymentStatusChanged::class]);
    $cancelled = testPspPayment($this->merchant, ['status' => PaymentStatus::Cancelled]);

    $this->postJson("/api/v1/test-psp/{$cancelled->key}/complete", ['action' => 'approve'])->assertStatus(409);

    expect($cancelled->refresh()->status)->toBe(PaymentStatus::Cancelled)
        ->and($cancelled->amount_received)->toBeNull();
    Event::assertNotDispatched(PaymentStatusChanged::class);
});

test('повторный approve и approve после decline не дают второго события', function () {
    Event::fake([PaymentStatusChanged::class]);
    $url = "/api/v1/test-psp/{$this->payment->key}/complete";

    $this->postJson($url, ['action' => 'approve'])->assertSuccessful();
    $this->postJson($url, ['action' => 'approve'])->assertStatus(409);
    $this->postJson($url, ['action' => 'decline'])->assertStatus(409);
    $this->postJson($url, ['action' => 'approve'])->assertStatus(409);

    expect($this->payment->refresh()->status)->toBe(PaymentStatus::Succeeded);
    Event::assertDispatchedTimes(PaymentStatusChanged::class, 1);
});

test('попытки чужого платежа не трогаются', function () {
    $other = testPspPayment($this->merchant);
    $mine = PaymentAttempt::create(['payment_intent_id' => $this->payment->id, 'connector' => 'test', 'status' => 'requires_action', 'amount' => 17502]);
    $foreign = PaymentAttempt::create(['payment_intent_id' => $other->id, 'connector' => 'test', 'status' => 'requires_action', 'amount' => 17502]);

    $this->postJson("/api/v1/test-psp/{$this->payment->key}/complete", ['action' => 'approve'])->assertSuccessful();

    expect($mine->refresh()->status)->toBe('succeeded')
        ->and($foreign->refresh()->status)->toBe('requires_action');
});
