<?php

declare(strict_types=1);

use App\DataTransferObjects\Refund\CreateRefundData;
use App\Events\PaymentStatusChanged;
use App\Services\RefundService;
use App\Services\WebhookReceiverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentAttempt;
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
        'connector_name' => 'cloudpayments',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['public_id' => 'pk', 'api_secret' => 'sekret'],
        'test_mode' => true,
    ]);
});

function cpPayment(MerchantAccount $merchant, array $overrides = []): PaymentIntent
{
    return PaymentIntent::create(array_merge([
        'merchant_account_id' => $merchant->id,
        'amount' => 5000,
        'currency' => 'RUB',
        'status' => PaymentStatus::RequiresCustomerAction,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
        'connector' => 'cloudpayments',
    ], $overrides));
}

/**
 * A CloudPayments notification the way the provider sends it: a signed form body.
 * Check is Status without AuthCode; Pay carries AuthCode.
 */
function cpNotify(object $test, array $params): TestResponse
{
    $body = http_build_query($params);

    return $test->call(
        'POST',
        "/api/v1/webhooks/{$test->merchant->key}/{$test->mca->key}",
        $params,
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_CONTENT_HMAC' => base64_encode(hash_hmac('sha256', $body, 'sekret', true)),
        ],
        $body,
    );
}

function cpCheckParams(PaymentIntent $payment, array $overrides = []): array
{
    return array_merge([
        'TransactionId' => 900001,
        'Amount' => '50.00',
        'Currency' => 'RUB',
        'InvoiceId' => $payment->key,
        'OperationType' => 'Payment',
        'Status' => 'Completed',
    ], $overrides);
}

function cpPayParams(PaymentIntent $payment, array $overrides = []): array
{
    return cpCheckParams($payment, array_merge(['AuthCode' => 'A1B2C3'], $overrides));
}

// --- Б2: Check одобряет только платёж, который ещё можно оплатить ---

test('check is refused with 13 for a payment that can no longer be paid', function (PaymentStatus $status) {
    $payment = cpPayment($this->merchant, ['status' => $status]);

    cpNotify($this, cpCheckParams($payment))->assertOk()->assertExactJson(['code' => 13]);

    expect($payment->fresh()->status)->toBe($status);
})->with([
    'succeeded' => PaymentStatus::Succeeded,
    'requires_capture' => PaymentStatus::RequiresCapture,
    'failed' => PaymentStatus::Failed,
    'cancelled' => PaymentStatus::Cancelled,
]);

test('check is refused with 20 for an expired payment', function () {
    $payment = cpPayment($this->merchant, ['status' => PaymentStatus::Expired]);

    cpNotify($this, cpCheckParams($payment))->assertOk()->assertExactJson(['code' => 20]);
});

test('check of a two-stage payment (Authorized, no AuthCode) is a check, not an authorization', function () {
    $payment = cpPayment($this->merchant, ['capture_method' => CaptureMethod::Manual]);

    cpNotify($this, cpCheckParams($payment, ['Status' => 'Authorized']))
        ->assertOk()->assertExactJson(['code' => 0]);
    expect($payment->fresh()->status)->toBe(PaymentStatus::RequiresCustomerAction);

    cpNotify($this, cpCheckParams($payment, ['Status' => 'Authorized', 'Amount' => '1.00']))
        ->assertOk()->assertExactJson(['code' => 12]);
    expect($payment->fresh()->status)->toBe(PaymentStatus::RequiresCustomerAction);
});

test('check is refused with 12 when the currency is not the one we billed', function () {
    $payment = cpPayment($this->merchant);

    cpNotify($this, cpCheckParams($payment, ['Currency' => 'KZT']))
        ->assertOk()->assertExactJson(['code' => 12]);
});

// --- Поздний успех: деньги у плательщика уже списаны ---

test('pay on an expired or failed payment brings it to succeeded and tells the merchant', function (PaymentStatus $from) {
    Event::fake([PaymentStatusChanged::class]);
    Log::spy();
    $payment = cpPayment($this->merchant, ['status' => $from]);

    cpNotify($this, cpPayParams($payment))->assertOk()->assertExactJson(['code' => 0]);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
    Event::assertDispatched(PaymentStatusChanged::class, fn ($e) => $e->previousStatus === $from->value);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'late'))->once();
})->with([PaymentStatus::Expired, PaymentStatus::Failed]);

test('authorized pay on an expired payment brings it to requires_capture', function () {
    Event::fake([PaymentStatusChanged::class]);
    $payment = cpPayment($this->merchant, ['status' => PaymentStatus::Expired, 'capture_method' => CaptureMethod::Manual]);

    cpNotify($this, cpPayParams($payment, ['Status' => 'Authorized']))->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::RequiresCapture);
    Event::assertDispatched(PaymentStatusChanged::class);
});

test('pay on a cancelled payment is put before the merchant', function () {
    Event::fake([PaymentStatusChanged::class]);
    Log::spy();
    $payment = cpPayment($this->merchant, ['status' => PaymentStatus::Cancelled]);

    cpNotify($this, cpPayParams($payment))->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::RequiresMerchantAction);
    Event::assertDispatched(PaymentStatusChanged::class);
    Log::shouldHaveReceived('error')->once();
});

test('a repeated pay on a succeeded payment changes nothing', function () {
    Event::fake([PaymentStatusChanged::class]);
    $payment = cpPayment($this->merchant, ['status' => PaymentStatus::Succeeded, 'amount_received' => 5000]);

    cpNotify($this, cpPayParams($payment))->assertOk()->assertExactJson(['code' => 0]);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
    Event::assertNotDispatched(PaymentStatusChanged::class);
});

// --- Б5: сумма и валюта в Pay ---

test('pay for another amount or currency is put before the merchant, not succeeded', function (array $overrides, string $errorCode) {
    Event::fake([PaymentStatusChanged::class]);
    Log::spy();
    $payment = cpPayment($this->merchant);

    cpNotify($this, cpPayParams($payment, $overrides))->assertOk();

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe(PaymentStatus::RequiresMerchantAction)
        ->and($fresh->error_code)->toBe($errorCode)
        ->and($fresh->amount_received)->toBeNull();
    Event::assertDispatched(PaymentStatusChanged::class);
    Log::shouldHaveReceived('error')->atLeast()->once();
})->with([
    'amount' => [['Amount' => '1.00'], 'amount_mismatch'],
    'currency' => [['Currency' => 'KZT'], 'currency_mismatch'],
]);

test('authorized pay for another amount does not become requires_capture', function () {
    $payment = cpPayment($this->merchant, ['capture_method' => CaptureMethod::Manual]);

    cpNotify($this, cpPayParams($payment, ['Status' => 'Authorized', 'Amount' => '1.00']))->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::RequiresMerchantAction);
});

test('late pay for another amount on an expired payment is put before the merchant too', function () {
    $payment = cpPayment($this->merchant, ['status' => PaymentStatus::Expired]);

    cpNotify($this, cpPayParams($payment, ['Amount' => '10.00']))->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::RequiresMerchantAction)
        ->and($payment->fresh()->error_code)->toBe('amount_mismatch');
});

test('amount_received is the amount the provider quotes, converted to minor units', function () {
    $payment = cpPayment($this->merchant, ['amount' => 13750]);

    cpNotify($this, cpPayParams($payment, ['Amount' => '137.5']))->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->fresh()->amount_received)->toBe(13750);
});

// --- Б6: TransactionId и попытка ---

function cpAttempt(PaymentIntent $payment, string $connector = 'cloudpayments'): PaymentAttempt
{
    return PaymentAttempt::create([
        'payment_intent_id' => $payment->id,
        'connector' => $connector,
        'status' => 'requires_action',
        'amount' => $payment->amount,
    ]);
}

test('pay marks the attempt succeeded and keeps the provider transaction id', function () {
    $payment = cpPayment($this->merchant);
    $attempt = cpAttempt($payment);
    $foreign = cpAttempt(cpPayment($this->merchant));

    cpNotify($this, cpPayParams($payment, ['TransactionId' => 777001]))->assertOk();

    expect($attempt->fresh()->status)->toBe('succeeded')
        ->and($attempt->fresh()->connector_transaction_id)->toBe('777001')
        ->and($foreign->fresh()->status)->toBe('requires_action')
        ->and($foreign->fresh()->connector_transaction_id)->toBeNull();
});

test('fail notification fails the payment and the attempt', function () {
    $payment = cpPayment($this->merchant);
    $attempt = cpAttempt($payment);

    // Fail carries Reason and ReasonCode, and no AuthCode.
    cpNotify($this, [
        'TransactionId' => 777002,
        'Amount' => '50.00',
        'Currency' => 'RUB',
        'InvoiceId' => $payment->key,
        'OperationType' => 'Payment',
        'Reason' => 'InsufficientFunds',
        'ReasonCode' => 5051,
    ])->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed)
        ->and($attempt->fresh()->status)->toBe('failed')
        ->and($attempt->fresh()->connector_transaction_id)->toBe('777002');
});

test('a payment paid through the notification can be refunded through the API', function () {
    Http::fake([
        'api.cloudpayments.ru/payments/refund' => Http::response(['Success' => true, 'Model' => ['TransactionId' => 888001]]),
    ]);
    $payment = cpPayment($this->merchant);
    cpAttempt($payment);

    cpNotify($this, cpPayParams($payment, ['TransactionId' => 777003]))->assertOk();

    $refund = app(RefundService::class)->create(
        new CreateRefundData(payment_id: $payment->key, amount: 2000),
        $this->merchant->id,
    );

    expect($refund->status)->toBe(RefundStatus::Succeeded);
    Http::assertSent(fn ($request) => $request['TransactionId'] === '777003');
});

// --- Б6: уведомления Refund и Cancel ---

function cpRefundParams(PaymentIntent $payment, array $overrides = []): array
{
    return array_merge([
        'TransactionId' => 990001,
        'PaymentTransactionId' => 777001,
        'Amount' => '20.00',
        'DateTime' => '2026-09-14 12:00:00',
        'OperationType' => 'Refund',
        'InvoiceId' => $payment->key,
        'AccountId' => 'user@example.com',
    ], $overrides);
}

test('refund made in the provider cabinet is recorded and the merchant is told', function () {
    Queue::fake();
    $payment = cpPayment($this->merchant, ['status' => PaymentStatus::Succeeded, 'amount_received' => 5000]);

    cpNotify($this, cpRefundParams($payment))->assertOk()->assertExactJson(['code' => 0]);
    // CloudPayments repeats a notification it is not sure we got.
    cpNotify($this, cpRefundParams($payment))->assertOk();

    $refund = Refund::sole();
    expect($refund->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->merchant_account_id)->toBe($this->merchant->id)
        ->and($refund->payment_intent_id)->toBe($payment->id)
        ->and($refund->amount)->toBe(2000)
        ->and($refund->currency)->toBe('RUB')
        ->and($refund->connector)->toBe('cloudpayments')
        ->and($refund->connector_refund_id)->toBe('990001')
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Succeeded);

    $event = WebhookEvent::sole();
    expect($event->event_type)->toBe('refund_succeeded')
        ->and($event->content['refund_id'])->toBe($refund->key)
        ->and($event->content['payment_id'])->toBe($payment->key)
        ->and($event->content['amount'])->toBe(2000);
});

test('refund notification for a refund we already know creates nothing', function () {
    Queue::fake();
    $payment = cpPayment($this->merchant, ['status' => PaymentStatus::Succeeded, 'amount_received' => 5000]);
    Refund::create([
        'payment_intent_id' => $payment->id,
        'merchant_account_id' => $this->merchant->id,
        'amount' => 2000,
        'currency' => 'RUB',
        'status' => RefundStatus::Succeeded,
        'connector' => 'cloudpayments',
        'connector_refund_id' => '990001',
    ]);

    cpNotify($this, cpRefundParams($payment))->assertOk();

    expect(Refund::count())->toBe(1)
        ->and(WebhookEvent::count())->toBe(0);
});

test('cancel notification cancels an authorized payment', function () {
    Event::fake([PaymentStatusChanged::class]);
    $payment = cpPayment($this->merchant, ['status' => PaymentStatus::RequiresCapture, 'capture_method' => CaptureMethod::Manual]);

    cpNotify($this, [
        'TransactionId' => 777004,
        'Amount' => '50.00',
        'DateTime' => '2026-09-14 12:00:00',
        'InvoiceId' => $payment->key,
        'AccountId' => 'user@example.com',
    ])->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Cancelled);
    Event::assertDispatched(PaymentStatusChanged::class);
});

test('check with the billed amount and currency on a payable payment gets 0', function () {
    $payment = cpPayment($this->merchant);

    Event::fake([PaymentStatusChanged::class]);

    cpNotify($this, cpCheckParams($payment))->assertOk()->assertExactJson(['code' => 0]);

    Event::assertNotDispatched(PaymentStatusChanged::class);
});
