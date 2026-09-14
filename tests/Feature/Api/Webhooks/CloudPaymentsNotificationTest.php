<?php

declare(strict_types=1);

use App\Events\PaymentStatusChanged;
use App\Services\WebhookReceiverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;

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

test('check with the billed amount and currency on a payable payment gets 0', function () {
    $payment = cpPayment($this->merchant);

    Event::fake([PaymentStatusChanged::class]);

    cpNotify($this, cpCheckParams($payment))->assertOk()->assertExactJson(['code' => 0]);

    Event::assertNotDispatched(PaymentStatusChanged::class);
});
