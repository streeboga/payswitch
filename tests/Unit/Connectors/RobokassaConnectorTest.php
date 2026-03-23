<?php

declare(strict_types=1);

use Streeboga\PaymentConnectors\ConnectorCapabilities;
use Streeboga\PaymentConnectors\Drivers\RobokassaConnector;
use Streeboga\PaymentConnectors\PaymentSessionResult;
use Streeboga\PaymentData\Enums\AmountUnit;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\SessionResultType;
use Tests\TestCase;

uses(TestCase::class);

// --- Helpers ---

function robokassaCredentials(array $overrides = []): array
{
    return array_merge([
        'login' => 'TestShop',
        'password1' => 'secret1',
        'password2' => 'secret2',
    ], $overrides);
}

function robokassaConnector(array $credentialOverrides = []): RobokassaConnector
{
    return new RobokassaConnector(robokassaCredentials($credentialOverrides));
}

// --- Capabilities ---

test('capabilities returns correct descriptor', function () {
    $caps = RobokassaConnector::capabilities();

    expect($caps)->toBeInstanceOf(ConnectorCapabilities::class)
        ->and($caps->amountUnit)->toBe(AmountUnit::Rubles)
        ->and($caps->fallbackSessionType)->toBe(SessionResultType::FormRedirect)
        ->and($caps->directMethods)->toHaveCount(2)
        ->and($caps->directMethods['card']->sessionType)->toBe(SessionResultType::FormRedirect)
        ->and($caps->directMethods['sbp']->sessionType)->toBe(SessionResultType::FormRedirect)
        ->and($caps->displayName('ru'))->toBe('Робокасса')
        ->and($caps->displayName('en'))->toBe('Robokassa');
});

// --- getName ---

test('getName returns robokassa', function () {
    expect(robokassaConnector()->getName())->toBe('robokassa');
});

// --- Signature generation ---

test('signature generation produces correct MD5 hash', function () {
    $connector = robokassaConnector();
    $result = $connector->createPaymentSession([
        'payment_id' => 'pay_test_123',
        'amount' => 10000,
        'description' => 'Test payment',
    ]);

    expect($result)->toBeInstanceOf(PaymentSessionResult::class);

    $arr = $result->toArray();
    $params = $arr['params'];

    $outSum = $params['OutSum'];
    $invId = $params['InvId'];
    $paymentId = $params['Shp_payment_id'];

    $expected = md5("TestShop:{$outSum}:{$invId}:secret1:Shp_payment_id={$paymentId}");

    expect($params['SignatureValue'])->toBe($expected);
});

// --- createPaymentSession ---

test('createPaymentSession returns formRedirect', function () {
    $connector = robokassaConnector();
    $result = $connector->createPaymentSession([
        'payment_id' => 'pay_abc',
        'amount' => 50000,
        'description' => 'Order #42',
    ]);

    expect($result)->toBeInstanceOf(PaymentSessionResult::class)
        ->and($result->type)->toBe(SessionResultType::FormRedirect);

    $arr = $result->toArray();
    expect($arr['url'])->toBe('https://auth.robokassa.ru/Merchant/Index.aspx')
        ->and($arr['method'])->toBe('POST');
});

test('createPaymentSession form params include all required fields', function () {
    $connector = robokassaConnector();
    $result = $connector->createPaymentSession([
        'payment_id' => 'pay_fields',
        'amount' => 25000,
        'description' => 'Widget purchase',
    ]);

    $params = $result->toArray()['params'];

    expect($params)
        ->toHaveKey('MerchantLogin', 'TestShop')
        ->toHaveKey('OutSum')
        ->toHaveKey('InvId')
        ->toHaveKey('Description', 'Widget purchase')
        ->toHaveKey('SignatureValue')
        ->toHaveKey('Culture', 'ru')
        ->toHaveKey('Encoding', 'utf-8')
        ->toHaveKey('Shp_payment_id', 'pay_fields');
});

test('IncCurrLabel set to BankCardPSR for card method', function () {
    $connector = robokassaConnector();
    $result = $connector->createPaymentSession([
        'payment_id' => 'pay_card',
        'amount' => 10000,
        'payment_method' => 'card',
    ]);

    $params = $result->toArray()['params'];
    expect($params['IncCurrLabel'])->toBe('BankCardPSR');
});

test('IncCurrLabel set to SBP for sbp method', function () {
    $connector = robokassaConnector();
    $result = $connector->createPaymentSession([
        'payment_id' => 'pay_sbp',
        'amount' => 10000,
        'payment_method' => 'sbp',
    ]);

    $params = $result->toArray()['params'];
    expect($params['IncCurrLabel'])->toBe('SBP');
});

test('IncCurrLabel not set when no payment method specified', function () {
    $connector = robokassaConnector();
    $result = $connector->createPaymentSession([
        'payment_id' => 'pay_default',
        'amount' => 10000,
    ]);

    $params = $result->toArray()['params'];
    expect($params)->not->toHaveKey('IncCurrLabel');
});

test('Shp_payment_id included in form params', function () {
    $connector = robokassaConnector();
    $result = $connector->createPaymentSession([
        'payment_id' => 'pay_shp_test',
        'amount' => 5000,
    ]);

    $params = $result->toArray()['params'];
    expect($params['Shp_payment_id'])->toBe('pay_shp_test');
});

// --- Amount formatting ---

test('amount formatted as rubles from kopecks', function () {
    $connector = robokassaConnector();
    $result = $connector->createPaymentSession([
        'payment_id' => 'pay_rub',
        'amount' => 10050,
    ]);

    $params = $result->toArray()['params'];
    expect($params['OutSum'])->toBe('100.50');
});

test('whole ruble amount formatted correctly', function () {
    $connector = robokassaConnector();
    $result = $connector->createPaymentSession([
        'payment_id' => 'pay_whole',
        'amount' => 50000,
    ]);

    $params = $result->toArray()['params'];
    expect($params['OutSum'])->toBe('500.00');
});

// --- InvId ---

test('InvId is numeric integer', function () {
    $connector = robokassaConnector();
    $result = $connector->createPaymentSession([
        'payment_id' => 'pay_inv_test',
        'amount' => 1000,
    ]);

    $params = $result->toArray()['params'];
    expect($params['InvId'])->toBeInt()
        ->and($params['InvId'])->toBeGreaterThan(0);
});

test('InvId is deterministic for same payment_id', function () {
    $connector = robokassaConnector();

    $result1 = $connector->createPaymentSession([
        'payment_id' => 'pay_deterministic',
        'amount' => 1000,
    ]);
    $result2 = $connector->createPaymentSession([
        'payment_id' => 'pay_deterministic',
        'amount' => 1000,
    ]);

    expect($result1->toArray()['params']['InvId'])
        ->toBe($result2->toArray()['params']['InvId']);
});

// --- Webhook signature verification ---

test('verifyWebhookSignature validates correct signature', function () {
    $connector = robokassaConnector();

    $outSum = '100.00';
    $invId = '12345';
    $paymentId = 'pay_01JEXAMPLE';

    $signature = md5("{$outSum}:{$invId}:secret2:Shp_payment_id={$paymentId}");

    $payload = http_build_query([
        'OutSum' => $outSum,
        'InvId' => $invId,
        'SignatureValue' => $signature,
        'Shp_payment_id' => $paymentId,
    ]);

    expect($connector->verifyWebhookSignature($payload, []))->toBeTrue();
});

test('verifyWebhookSignature rejects invalid signature', function () {
    $connector = robokassaConnector();

    $payload = http_build_query([
        'OutSum' => '100.00',
        'InvId' => '12345',
        'SignatureValue' => 'invalid_signature_hash',
        'Shp_payment_id' => 'pay_01JEXAMPLE',
    ]);

    expect($connector->verifyWebhookSignature($payload, []))->toBeFalse();
});

test('verifyWebhookSignature rejects payload without SignatureValue', function () {
    $connector = robokassaConnector();

    $payload = http_build_query([
        'OutSum' => '100.00',
        'InvId' => '12345',
        'Shp_payment_id' => 'pay_01JEXAMPLE',
    ]);

    expect($connector->verifyWebhookSignature($payload, []))->toBeFalse();
});

test('verifyWebhookSignature handles multiple Shp_ params sorted', function () {
    $connector = robokassaConnector();

    $outSum = '200.00';
    $invId = '99999';

    // Shp_ params must be sorted alphabetically: Shp_alpha, Shp_beta
    $signature = md5("{$outSum}:{$invId}:secret2:Shp_alpha=aaa:Shp_beta=bbb");

    $payload = http_build_query([
        'OutSum' => $outSum,
        'InvId' => $invId,
        'SignatureValue' => $signature,
        'Shp_beta' => 'bbb',
        'Shp_alpha' => 'aaa',
    ]);

    expect($connector->verifyWebhookSignature($payload, []))->toBeTrue();
});

test('verifyWebhookSignature is case-insensitive', function () {
    $connector = robokassaConnector();

    $outSum = '100.00';
    $invId = '12345';
    $paymentId = 'pay_01JEXAMPLE';

    $signature = strtoupper(md5("{$outSum}:{$invId}:secret2:Shp_payment_id={$paymentId}"));

    $payload = http_build_query([
        'OutSum' => $outSum,
        'InvId' => $invId,
        'SignatureValue' => $signature,
        'Shp_payment_id' => $paymentId,
    ]);

    expect($connector->verifyWebhookSignature($payload, []))->toBeTrue();
});

// --- extractPaymentIdFromWebhook ---

test('extractPaymentIdFromWebhook returns Shp_payment_id', function () {
    $connector = robokassaConnector();

    $paymentId = $connector->extractPaymentIdFromWebhook([
        'OutSum' => '100.00',
        'InvId' => '12345',
        'SignatureValue' => 'abc123',
        'Shp_payment_id' => 'pay_01JEXAMPLE',
    ]);

    expect($paymentId)->toBe('pay_01JEXAMPLE');
});

test('extractPaymentIdFromWebhook returns null when missing', function () {
    $connector = robokassaConnector();

    $paymentId = $connector->extractPaymentIdFromWebhook([
        'OutSum' => '100.00',
        'InvId' => '12345',
    ]);

    expect($paymentId)->toBeNull();
});

// --- Not supported operations ---

test('purchase returns not_supported', function () {
    $connector = robokassaConnector();
    $result = $connector->purchase(['amount' => 10000]);

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe('not_supported')
        ->and($result['transaction_id'])->toBeNull();
});

test('authorize returns not_supported', function () {
    $connector = robokassaConnector();
    $result = $connector->authorize(['amount' => 10000]);

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe('not_supported');
});

test('capture returns not_supported', function () {
    $connector = robokassaConnector();
    $result = $connector->capture(['transaction_id' => 'txn_123']);

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe('not_supported');
});

test('refund returns not_supported', function () {
    $connector = robokassaConnector();
    $result = $connector->refund(['transaction_id' => 'txn_123', 'amount' => 5000]);

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe('not_supported');
});

test('void returns not_supported', function () {
    $connector = robokassaConnector();
    $result = $connector->void(['transaction_id' => 'txn_123']);

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe('not_supported');
});

test('getPaymentStatus returns not_supported', function () {
    $connector = robokassaConnector();
    $result = $connector->getPaymentStatus(['transaction_id' => 'txn_123']);

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe('not_supported');
});

// --- Status mapping ---

test('mapWebhookEventToStatus maps result to Succeeded', function () {
    $connector = robokassaConnector();

    expect($connector->mapWebhookEventToStatus('result'))->toBe(PaymentStatus::Succeeded)
        ->and($connector->mapWebhookEventToStatus('unknown'))->toBeNull();
});

test('mapPaymentStatusToInternal maps known statuses', function () {
    $connector = robokassaConnector();

    expect($connector->mapPaymentStatusToInternal('completed'))->toBe(PaymentStatus::Succeeded)
        ->and($connector->mapPaymentStatusToInternal('result'))->toBe(PaymentStatus::Succeeded)
        ->and($connector->mapPaymentStatusToInternal('unknown'))->toBeNull();
});

// --- testConnection ---

test('testConnection succeeds with valid credentials format', function () {
    $connector = robokassaConnector();
    $result = $connector->testConnection();

    expect($result['success'])->toBeTrue();
});

test('testConnection fails with missing credentials', function () {
    $connector = new RobokassaConnector([]);
    $result = $connector->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('Missing credentials');
});

// --- Webhook fixture ---

test('webhook fixture matches expected format', function () {
    $fixture = json_decode(
        file_get_contents(base_path('tests/Fixtures/Webhooks/robokassa_result_url.json')),
        true,
    );

    expect($fixture)
        ->toHaveKey('OutSum')
        ->toHaveKey('InvId')
        ->toHaveKey('SignatureValue')
        ->toHaveKey('Shp_payment_id');
});
