<?php

declare(strict_types=1);

use App\Services\WebhookReceiverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;

/*
 * Уведомления в том виде, в каком их шлют сами провайдеры, и ответы, которых они ждут.
 * Раньше приёмник брал событие из `type`, а тесты его подставляли — реальные
 * уведомления YooKassa, RBS и Robokassa платёж не двигали.
 */

covers(WebhookReceiverService::class);

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
});

function providerMca(object $test, string $name, array $details): MerchantConnectorAccount
{
    return MerchantConnectorAccount::create([
        'merchant_account_id' => $test->merchant->id,
        'business_profile_id' => $test->profile->id,
        'connector_name' => $name,
        'connector_type' => 'fiz_operations',
        'connector_account_details' => $details,
        'test_mode' => true,
    ]);
}

function providerPayment(object $test, string $connector, int $amount = 10000): PaymentIntent
{
    return PaymentIntent::create([
        'merchant_account_id' => $test->merchant->id,
        'amount' => $amount,
        'currency' => 'RUB',
        'status' => PaymentStatus::RequiresCustomerAction,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
        'connector' => $connector,
    ]);
}

function rbsCallbackUrl(string $url, array $params, string $secret): string
{
    ksort($params, SORT_STRING);
    $signed = '';
    foreach ($params as $k => $v) {
        $signed .= "{$k};{$v};";
    }

    return $url.'?'.http_build_query($params + ['checksum' => strtoupper(hash_hmac('sha256', $signed, $secret))]);
}

test('rbs: deposited with status 1 succeeds the payment, with status 0 fails it', function (string $status, PaymentStatus $expected) {
    $mca = providerMca($this, 'sberbank', ['username' => 'u', 'password' => 'p', 'callback_secret' => 'shared_key']);
    $payment = providerPayment($this, 'sberbank');

    $params = ['mdOrder' => 'abc', 'operation' => 'deposited', 'orderNumber' => $payment->key, 'status' => $status];

    $this->getJson(rbsCallbackUrl("/api/v1/webhooks/{$this->merchant->key}/{$mca->key}", $params, 'shared_key'))
        ->assertOk();

    expect($payment->fresh()->status)->toBe($expected);
})->with([
    'success' => ['1', PaymentStatus::Succeeded],
    'failure' => ['0', PaymentStatus::Failed],
]);

test('robokassa: a validly signed ResultURL succeeds the payment and is answered OK{InvId}', function () {
    $mca = providerMca($this, 'robokassa', ['login' => 'shop', 'password1' => 'p1', 'password2' => 'p2']);
    $payment = providerPayment($this, 'robokassa');

    $params = [
        'OutSum' => '100.00',
        'InvId' => '12345',
        'Shp_payment_id' => $payment->key,
    ];
    $params['SignatureValue'] = md5("100.00:12345:p2:Shp_payment_id={$payment->key}");
    $body = http_build_query($params);

    $response = $this->call(
        'POST',
        "/api/v1/webhooks/{$this->merchant->key}/{$mca->key}",
        $params,
        [],
        [],
        ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
        $body,
    );

    $response->assertOk();
    expect($response->getContent())->toBe('OK12345')
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->fresh()->amount_received)->toBe(10000);
});

test('tbank: a confirmed notification is answered with a bare OK', function () {
    $mca = providerMca($this, 'tbank', ['terminal_key' => 'TinkoffBankTest', 'password' => 'secret']);
    $payment = providerPayment($this, 'tbank');

    $body = [
        'TerminalKey' => 'TinkoffBankTest',
        'OrderId' => $payment->key,
        'Success' => true,
        'Status' => 'CONFIRMED',
        'PaymentId' => 2304882,
        'ErrorCode' => '0',
        'Amount' => 10000,
    ];
    $signed = $body + ['Password' => 'secret'];
    ksort($signed);
    $body['Token'] = hash('sha256', implode('', array_map(
        fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v,
        $signed,
    )));

    $response = $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$mca->key}", $body);

    $response->assertOk();
    expect($response->getContent())->toBe('OK')
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
});
