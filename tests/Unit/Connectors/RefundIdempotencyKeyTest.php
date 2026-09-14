<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentConnectors\ConnectorErrorNormalizer;
use Streeboga\PaymentConnectors\Drivers\CloudPaymentsConnector;
use Streeboga\PaymentConnectors\Drivers\RbsConnector;
use Streeboga\PaymentConnectors\Drivers\TBankConnector;
use Streeboga\PaymentConnectors\Drivers\TochkaConnector;
use Streeboga\PaymentConnectors\Drivers\YooKassaConnector;
use Tests\TestCase;

uses(TestCase::class);

// Ключ идемпотентности возврата у провайдера — ключ нашего возврата (ref_…), который
// RefundService передаёт в refund_id.

function refundYooKassa(): YooKassaConnector
{
    return new YooKassaConnector(['shop_id' => '123456', 'secret_key' => 'test_secret']);
}

test('YooKassa: Idempotence-Key — ключ возврата, два равных частичных возврата не схлопываются', function () {
    Http::fake(['api.yookassa.ru/v3/refunds' => Http::response(['id' => 'yk_ref', 'status' => 'succeeded'])]);

    refundYooKassa()->refund(['payment_id' => 'pay_1', 'refund_id' => 'ref_AAA', 'amount' => 500, 'currency' => 'RUB', 'transaction_id' => 'yk_txn']);
    refundYooKassa()->refund(['payment_id' => 'pay_1', 'refund_id' => 'ref_BBB', 'amount' => 500, 'currency' => 'RUB', 'transaction_id' => 'yk_txn']);

    $keys = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('Idempotence-Key')[0])->all();
    expect($keys)->toBe(['ref_AAA', 'ref_BBB']);
});

test('YooKassa: pending или неразобранный ответ на возврат — не отказ', function (array|string $body, int $status) {
    Http::fake(['api.yookassa.ru/v3/refunds' => Http::response($body, $status)]);

    $result = refundYooKassa()->refund(['refund_id' => 'ref_P', 'amount' => 500, 'currency' => 'RUB', 'transaction_id' => 'yk_txn']);

    expect($result['success'])->toBeFalse()
        ->and(ConnectorErrorNormalizer::isIndeterminate($result))->toBeTrue();
})->with([
    'pending' => [['id' => 'yk_ref_p', 'status' => 'pending'], 200],
    '5xx без тела' => ['<html>Bad Gateway</html>', 502],
]);

test('YooKassa: canceled или ошибка запроса на возврат — явный отказ', function (array $body, int $status) {
    Http::fake(['api.yookassa.ru/v3/refunds' => Http::response($body, $status)]);

    $result = refundYooKassa()->refund(['refund_id' => 'ref_D', 'amount' => 500, 'currency' => 'RUB', 'transaction_id' => 'yk_txn']);

    expect($result['success'])->toBeFalse()
        ->and(ConnectorErrorNormalizer::isIndeterminate($result))->toBeFalse();
})->with([
    'canceled' => [['id' => 'yk_ref_c', 'status' => 'canceled', 'cancellation_details' => ['reason' => 'insufficient_funds']], 200],
    'error' => [['type' => 'error', 'code' => 'invalid_request', 'description' => 'Refund amount exceeds'], 400],
]);

test('Rbs: пустой ответ на refund.do — не успех, а неизвестный исход', function () {
    Http::fake(['*' => Http::response('<html>502 Bad Gateway</html>', 502)]);

    $result = (new RbsConnector(['username' => 'u', 'password' => 'p']))
        ->refund(['transaction_id' => 'order-1', 'amount' => 1000, 'refund_id' => 'ref_R']);

    expect($result['success'])->toBeFalse()
        ->and(ConnectorErrorNormalizer::isIndeterminate($result))->toBeTrue();
});

test('Tochka: 5xx на возврат — неизвестный исход, 4xx — отказ', function (int $status, bool $indeterminate) {
    Http::fake(['*' => Http::response(['message' => 'fail'], $status)]);

    $result = (new TochkaConnector(['token' => 't', 'customer_code' => 'c']))
        ->refund(['transaction_id' => 'op-1', 'amount' => 1000, 'refund_id' => 'ref_T']);

    expect($result['success'])->toBeFalse()
        ->and(ConnectorErrorNormalizer::isIndeterminate($result))->toBe($indeterminate);
})->with([
    '503' => [503, true],
    '400' => [400, false],
]);

test('CloudPayments: ключ возврата уходит в X-Request-ID', function () {
    Http::fake(['api.cloudpayments.ru/payments/refund' => Http::response(['Success' => true, 'Model' => ['TransactionId' => 1]])]);

    (new CloudPaymentsConnector(['public_id' => 'pk_test', 'api_secret' => 'secret']))
        ->refund(['transaction_id' => 1, 'amount' => 3000, 'refund_id' => 'ref_CP1']);

    Http::assertSent(fn ($request) => $request->header('X-Request-ID') === ['ref_CP1']);
});

test('TBank: ключ возврата уходит в ExternalRequestId и входит в токен', function () {
    Http::fake(['securepay.tinkoff.ru/*' => Http::response(['Success' => true, 'ErrorCode' => '0', 'PaymentId' => 99005])]);

    (new TBankConnector(['terminal_key' => 'TinkoffBankTest', 'password' => 'test_password', 'base_url' => 'https://securepay.tinkoff.ru/v2']))
        ->refund(['transaction_id' => '99005', 'amount' => 20000, 'refund_id' => 'ref_TB1']);

    Http::assertSent(function ($request) {
        $body = $request->data();
        // Ключи по алфавиту: Amount, ExternalRequestId, Password, PaymentId, TerminalKey.
        $expected = hash('sha256', '20000ref_TB1test_password99005TinkoffBankTest');

        return $body['ExternalRequestId'] === 'ref_TB1' && $body['Token'] === $expected;
    });
});
