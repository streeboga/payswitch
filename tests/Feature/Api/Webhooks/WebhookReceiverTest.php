<?php

declare(strict_types=1);

use App\Services\WebhookReceiverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
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
});

test('returns 404 for non-existent merchant key', function () {
    $this->postJson("/api/v1/webhooks/fake_merchant/{$this->mca->key}", ['type' => 'test'])
        ->assertStatus(404)
        ->assertJson(['status' => 'ignored']);
});

test('returns 404 for non-existent mca key', function () {
    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/fake_mca", ['type' => 'test'])
        ->assertStatus(404)
        ->assertJson(['status' => 'ignored']);
});

test('returns 404 when mca does not belong to merchant', function () {
    $org2 = Organization::create(['name' => 'Org2']);
    $merchant2 = MerchantAccount::create(['org_id' => $org2->id, 'name' => 'M2']);

    $this->postJson("/api/v1/webhooks/{$merchant2->key}/{$this->mca->key}", ['type' => 'test'])
        ->assertStatus(404)
        ->assertJson(['status' => 'ignored']);
});

test('accepts webhook from test connector without signature', function () {
    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'payment.succeeded',
    ])->assertOk()->assertJson(['status' => 'ok']);
});

test('rejects webhook from stripe connector without signature header', function () {
    $this->mca->update(['connector_name' => 'stripe']);

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'payment_intent.succeeded',
    ])->assertStatus(401)
        ->assertJson(['status' => 'invalid_signature']);
});

test('processes payment status update from webhook and sets amount_received', function () {
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
        ->assertJson(['code' => 0]);

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe(PaymentStatus::Succeeded)
        ->and($fresh->amount_received)->toBe(5000);

    // Verify exact values in DB including connector field
    $this->assertDatabaseHas('payment_intents', [
        'id' => $payment->id,
        'status' => 'succeeded',
        'amount_received' => 5000,
        'connector' => 'cloudpayments',
    ]);
});

test('processes failed webhook and does not set amount_received', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 3000,
        'currency' => 'USD',
        'status' => PaymentStatus::Processing,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
    ]);

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'payment.failed',
        'payment_id' => $payment->key,
    ])->assertOk()
        ->assertJson(['status' => 'ok']);

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe(PaymentStatus::Failed)
        ->and($fresh->amount_received)->toBeNull();

    // Verify DB state: status changed, amount_received stays null
    $this->assertDatabaseHas('payment_intents', [
        'id' => $payment->id,
        'status' => 'failed',
        'connector' => 'test',
    ]);
});

test('ignores webhook with invalid payment status transition', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000,
        'currency' => 'USD',
        'status' => PaymentStatus::Succeeded,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
    ]);

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'payment.canceled',
        'payment_id' => $payment->key,
    ])->assertOk();

    $fresh = $payment->fresh();
    // Status should NOT change — Succeeded is terminal
    expect($fresh->status)->toBe(PaymentStatus::Succeeded)
        ->and($fresh->amount_received)->toBeNull();

    // Verify DB still has original status — no mutation
    $this->assertDatabaseHas('payment_intents', [
        'id' => $payment->id,
        'status' => 'succeeded',
    ]);
});

test('unknown webhook type does not change payment status', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 2500,
        'currency' => 'EUR',
        'status' => PaymentStatus::Processing,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
    ]);

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'some.unknown.event',
        'payment_id' => $payment->key,
    ])->assertOk()
        ->assertJson(['status' => 'ok']);

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe(PaymentStatus::Processing)
        ->and($fresh->amount_received)->toBeNull();

    // Verify DB unchanged — unknown event types must not mutate payment
    $this->assertDatabaseHas('payment_intents', [
        'id' => $payment->id,
        'status' => 'processing',
        'amount' => 2500,
        'currency' => 'EUR',
    ]);
});

test('webhook for non-existent payment still returns ok', function () {
    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'payment.succeeded',
        'payment_id' => 'pay_nonexistent_key_12345',
    ])->assertOk()
        ->assertJson(['status' => 'ok']);
});

test('webhook without extractable payment id returns ok without errors', function () {
    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'payment.succeeded',
        // no payment_id or data.object.metadata.payment_id
    ])->assertOk()
        ->assertJson(['status' => 'ok']);
});

test('does not leak internal error details in response', function () {
    $response = $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'payment.succeeded',
        'data' => 'invalid_data',
    ]);

    $response->assertOk()
        ->assertJson(['status' => 'ok'])
        ->assertJsonMissing(['exception'])
        ->assertJsonMissing(['trace']);
});

test('cancelled webhook transitions requires_confirmation payment to cancelled', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 7500,
        'currency' => 'USD',
        'status' => PaymentStatus::RequiresConfirmation,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
    ]);

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'payment.canceled',
        'payment_id' => $payment->key,
    ])->assertOk();

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe(PaymentStatus::Cancelled)
        ->and($fresh->amount_received)->toBeNull();

    // Verify exact DB state after cancellation webhook
    $this->assertDatabaseHas('payment_intents', [
        'id' => $payment->id,
        'status' => 'cancelled',
        'amount' => 7500,
    ]);
});

// --- Signature verification per connector: valid -> 200, wrong -> 401, absent -> 401 ---

function receiverRsaKeypair(): array
{
    static $pair = null;
    if ($pair !== null) {
        return $pair;
    }

    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $privatePem);
    $rsa = openssl_pkey_get_details($res)['rsa'];
    $b64 = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

    return $pair = [$privatePem, (string) json_encode(['kty' => 'RSA', 'e' => $b64($rsa['e']), 'n' => $b64($rsa['n'])])];
}

function receiverJwt(array $claims, string $privatePem): string
{
    $b64 = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    $input = $b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64((string) json_encode($claims));
    openssl_sign($input, $signature, $privatePem, OPENSSL_ALGO_SHA256);

    return $input.'.'.$b64($signature);
}

test('rbs callback: valid checksum passes, wrong and absent are refused', function () {
    $this->mca->update([
        'connector_name' => 'sberbank',
        'connector_account_details' => ['username' => 'u', 'password' => 'p', 'callback_secret' => 'shared_key'],
    ]);
    $url = "/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}";

    $params = ['mdOrder' => 'abc', 'operation' => 'deposited', 'orderNumber' => 'pay_1', 'status' => '1'];
    ksort($params, SORT_STRING);
    $signed = '';
    foreach ($params as $k => $v) {
        $signed .= "{$k};{$v};";
    }
    $checksum = strtoupper(hash_hmac('sha256', $signed, 'shared_key'));

    $this->getJson($url.'?'.http_build_query($params + ['checksum' => $checksum]))->assertOk();
    $this->getJson($url.'?'.http_build_query($params + ['checksum' => str_repeat('A', 64)]))->assertStatus(401);
    $this->getJson($url.'?'.http_build_query($params))->assertStatus(401);
});

test('tochka webhook: valid JWT passes, wrong key and absent signature are refused', function () {
    [$privatePem, $jwk] = receiverRsaKeypair();

    $this->mca->update([
        'connector_name' => 'tochka',
        'connector_account_details' => ['token' => 't', 'customer_code' => 'c', 'webhook_public_key' => $jwk],
    ]);
    $url = "/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}";
    $claims = ['webhookType' => 'acquiringInternetPayment', 'paymentLinkId' => 'pay_1', 'status' => 'APPROVED'];

    $post = fn (string $body) => $this->call(
        'POST', $url, [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'], $body,
    );

    $post(receiverJwt($claims, $privatePem))->assertOk();

    $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($other, $otherPem);
    $post(receiverJwt($claims, $otherPem))->assertStatus(401);

    $post('')->assertStatus(401);
});

test('yookassa webhook: published address passes, any other and none are refused', function () {
    $this->mca->update([
        'connector_name' => 'yookassa',
        'connector_account_details' => ['shop_id' => '1', 'secret_key' => 's'],
    ]);
    $url = "/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}";
    $body = ['type' => 'notification', 'event' => 'payment.succeeded'];

    $this->withServerVariables(['REMOTE_ADDR' => '185.71.76.5'])->postJson($url, $body)->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '1.2.3.4'])->postJson($url, $body)->assertStatus(401);
    // A forged header must not stand in for the peer address.
    $this->withServerVariables(['REMOTE_ADDR' => '1.2.3.4'])
        ->postJson($url, $body, ['x-payswitch-source-ip' => '185.71.76.5'])
        ->assertStatus(401);
});

test('cloudpayments webhook: valid HMAC passes, wrong and absent are refused', function () {
    $this->mca->update([
        'connector_name' => 'cloudpayments',
        'connector_account_details' => ['public_id' => 'pk', 'api_secret' => 'sekret'],
    ]);
    config()->set('payswitch.allow_unsigned_webhooks', false);
    $url = "/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}";

    $body = ['Status' => 'Completed', 'InvoiceId' => 'pay_1'];
    $hmac = base64_encode(hash_hmac('sha256', (string) json_encode($body), 'sekret', true));

    $this->postJson($url, $body, ['Content-HMAC' => $hmac])->assertOk();
    $this->postJson($url, $body, ['Content-HMAC' => base64_encode('nope')])->assertStatus(401);
    $this->postJson($url, $body)->assertStatus(401);
});

/**
 * CloudPayments decides whether to charge the card by reading our answer to the check
 * notification. A 200 is not enough: without `code` the payer is told the payment cannot
 * be accepted, which is exactly how a live test run failed before this was fixed.
 *
 * @see https://developers.cloudpayments.ru/#uvedomleniya
 */
function cloudPaymentsCheck(object $test, PaymentIntent $payment, string $amount): TestResponse
{
    $test->mca->update([
        'connector_name' => 'cloudpayments',
        'connector_account_details' => ['public_id' => 'pk', 'api_secret' => 'sekret'],
    ]);

    // Status=Completed with no AuthCode is a check notification, and CloudPayments quotes
    // the amount in rubles. Sent as a form body, which is what the widget path produces.
    $params = [
        'TransactionId' => 1234,
        'Amount' => $amount,
        'Currency' => 'RUB',
        'InvoiceId' => $payment->key,
        'Status' => 'Completed',
    ];

    // Both, deliberately: Symfony fills the request bag from $params and never from the
    // raw body for a form POST, while the signature is over the body the way the provider
    // sends it. Passing only one of the two silently tests nothing.
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

test('answers the CloudPayments check with code 0 when the amount is the one we billed', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 13700,
        'currency' => 'RUB',
        'status' => PaymentStatus::RequiresCustomerAction,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
    ]);

    cloudPaymentsCheck($this, $payment, '137.00')
        ->assertOk()
        ->assertExactJson(['code' => 0]);

    // A check must not move the payment on its own — that is the pay notification's job.
    expect($payment->fresh()->status)->toBe(PaymentStatus::RequiresCustomerAction);
});

test('refuses the CloudPayments check when the payer changed the amount', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 13700,
        'currency' => 'RUB',
        'status' => PaymentStatus::RequiresCustomerAction,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
    ]);

    cloudPaymentsCheck($this, $payment, '1.00')
        ->assertOk()
        ->assertExactJson(['code' => 12]);
});

test('refuses the CloudPayments check for a payment that is not ours', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 13700,
        'currency' => 'RUB',
        'status' => PaymentStatus::RequiresCustomerAction,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
    ]);
    $payment->key = 'pay_01NOTOURS';

    cloudPaymentsCheck($this, $payment, '137.00')
        ->assertOk()
        ->assertExactJson(['code' => 13]);
});

// --- С5: неподписанный коннектор не двигает чужие платежи и чужие возвраты ---

test('webhook of one connector does not move a payment conducted by another', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000,
        'currency' => 'RUB',
        'status' => PaymentStatus::RequiresCustomerAction,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
        'connector' => 'cloudpayments',
    ]);

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'payment.succeeded',
        'payment_id' => $payment->key,
    ])->assertOk();

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe(PaymentStatus::RequiresCustomerAction)
        ->and($fresh->connector)->toBe('cloudpayments')
        ->and(WebhookEvent::count())->toBe(0);
});

test('refund webhook does not touch a refund of another merchant', function () {
    $org = Organization::create(['name' => 'Other']);
    $other = MerchantAccount::create(['org_id' => $org->id, 'name' => 'B']);
    $payment = PaymentIntent::create([
        'merchant_account_id' => $other->id,
        'amount' => 5000,
        'currency' => 'RUB',
        'status' => PaymentStatus::Succeeded,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
        'connector' => 'test',
    ]);
    $refund = Refund::create([
        'payment_intent_id' => $payment->id,
        'merchant_account_id' => $other->id,
        'amount' => 1000,
        'currency' => 'RUB',
        'status' => RefundStatus::Pending,
        'connector' => 'test',
        'connector_refund_id' => 're_shared',
    ]);

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'refund.failed',
        'object' => ['id' => 're_shared'],
    ])->assertOk();

    expect($refund->fresh()->status)->toBe(RefundStatus::Pending);
});

test('a malformed payload that raises a TypeError is refused, not a 500', function () {
    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'payment.succeeded',
        'payment_id' => ['not', 'a', 'string'],
    ])->assertOk();
});
