<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Support\IdGenerator;

uses(RefreshDatabase::class);

/**
 * Кассовый чек (54-ФЗ): состав приходит с платежом, уходит кассе CloudPayments через
 * виджет или JsonData, а номер и ссылка выданного чека возвращаются уведомлением Receipt.
 * Формат CustomerReceipt — https://developers.cloudkassir.ru/#customerreceipt.
 */
beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();

    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    $rawKey = IdGenerator::apiKey('sandbox');
    ApiKey::create(['merchant_account_id' => $this->merchant->id, 'key_hash' => hash('sha256', $rawKey), 'key_prefix' => substr($rawKey, 0, 20), 'name' => 'Test']);
    $this->headers = ['api-key' => $rawKey];

    $this->mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id, 'business_profile_id' => $profile->id,
        'connector_name' => 'cloudpayments', 'connector_type' => 'fiz_operations',
        'connector_account_details' => ['public_id' => 'pk_fake', 'api_secret' => 'sekret'],
        'payment_methods_enabled' => [['payment_method' => 'card']], 'test_mode' => true,
    ]);

    // Ровно то, что шлёт invoicing-service (ConfiguredFiscalReceipt): суммы в копейках.
    $this->receipt = [
        'taxation_system' => 'usn_income',
        'email' => 'buyer@example.com',
        'items' => [[
            'label' => 'Пополнение баланса', 'quantity' => 1, 'price' => 50000, 'amount' => 50000,
            'vat' => 'none', 'payment_method' => 'advance', 'payment_object' => 'payment',
        ]],
    ];
    $this->create = fn (array $extra = []) => $this->postJson('/api/v1/payments', $extra + [
        'amount' => 50000, 'currency' => 'RUB', 'description' => 'Пополнение', 'receipt' => $this->receipt,
    ], $this->headers);

    $this->notify = function (array $params) {
        $body = http_build_query($params);

        return $this->call('POST', "/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", $params, [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_CONTENT_HMAC' => base64_encode(hash_hmac('sha256', $body, 'sekret', true)),
        ], $body);
    };
});

test('чек хранится у платежа, а виджету CloudPayments уходит CustomerReceipt в кодах кассы', function () {
    $created = ($this->create)()->assertCreated()->assertJsonPath('data.attributes.receipt', $this->receipt);
    $id = $created->json('data.id');

    $params = $this->postJson("/api/v1/payments/{$id}/confirm", ['payment_method' => 'card'], $this->headers + [
        'X-Client-Secret' => $created->json('data.attributes.client_secret'),
    ])->assertOk()->json('data.attributes.metadata.params');

    expect($params)->toBe([
        'publicId' => 'pk_fake',
        'amount' => 500,
        'currency' => 'RUB',
        'description' => 'Пополнение',
        'invoiceId' => $id,
        'email' => 'buyer@example.com',
        'data' => ['CloudPayments' => ['CustomerReceipt' => [
            'items' => [[
                'label' => 'Пополнение баланса', 'price' => 500, 'quantity' => 1, 'amount' => 500,
                'vat' => null,      // «НДС не облагается»; 0 был бы «НДС 0 %»
                'method' => 3,      // AdvancePay, аванс
                'object' => 10,     // Payment, платёж
            ]],
            'taxationSystem' => 1,  // УСН (доход)
            'email' => 'buyer@example.com',
            'amounts' => ['electronic' => 500],
        ]]],
    ]);
});

test('оплата по криптограмме несёт тот же чек в JsonData', function () {
    Http::fake(['api.cloudpayments.ru/*' => Http::response(['Success' => true, 'Model' => ['TransactionId' => 77, 'Amount' => 500]])]);

    ($this->create)([
        'confirm' => true, 'payment_method' => 'card',
        'payment_method_data' => ['card' => ['cryptogram' => 'crypto', 'card_holder_name' => 'A B']],
    ])->assertCreated();

    Http::assertSent(function ($request) {
        $receipt = json_decode($request['JsonData'], true)['CloudPayments']['CustomerReceipt'];

        return $request['Email'] === 'buyer@example.com' && $receipt['taxationSystem'] === 1
            && $receipt['items'][0]['vat'] === null && $receipt['amounts'] === ['electronic' => 500];
    });
});

test('без чека виджет получает прежние параметры', function () {
    $created = ($this->create)(['receipt' => null])->assertCreated()->assertJsonPath('data.attributes.receipt', null);

    $params = $this->postJson("/api/v1/payments/{$created->json('data.id')}/confirm", ['payment_method' => 'card'], $this->headers + [
        'X-Client-Secret' => $created->json('data.attributes.client_secret'),
    ])->json('data.attributes.metadata.params');

    expect(array_keys($params))->toBe(['publicId', 'amount', 'currency', 'description', 'invoiceId']);
});

test('чек с суммой позиций не равной сумме платежа отвергается', function () {
    ($this->create)(['amount' => 50001])->assertStatus(422)->assertJsonPath('errors.0.source.pointer', '/receipt/items');
});

test('чек без позиций или с незнакомым значением словаря отвергается', function (array $receipt) {
    ($this->create)(['receipt' => $receipt + $this->receipt])->assertStatus(422);
})->with([
    'нет позиций' => [['items' => []]],
    'система налогообложения' => [['taxation_system' => 'envd']],
    'почта' => [['email' => 'не почта']],
]);

test('уведомление Receipt сохраняет номер и ссылку чека и не трогает статус', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id, 'amount' => 50000, 'currency' => 'RUB',
        'status' => PaymentStatus::Succeeded, 'attempt_count' => 1, 'connector' => 'cloudpayments',
    ]);
    $receipt = [
        'Id' => 'sZrjQQ6btF', 'DocumentNumber' => 1323, 'FiscalSign' => '1016942', 'Type' => 'Income',
        'Url' => 'https://receipts.ru/sZrjQQ6btF', 'QrCodeUrl' => 'https://qr.cloudpayments.ru/receipt?q=x',
        'TransactionId' => 9001, 'Amount' => 500, 'InvoiceId' => $payment->key, 'Receipt' => '{}',
    ];

    ($this->notify)($receipt)->assertOk()->assertExactJson(['code' => 0]);

    // Чек возврата по тому же заказу чек прихода не затирает.
    ($this->notify)(['Id' => 'refund', 'Type' => 'IncomeReturn', 'Url' => 'https://receipts.ru/refund'] + $receipt)
        ->assertExactJson(['code' => 0]);

    $this->getJson("/api/v1/payments/{$payment->key}", $this->headers + ['X-Client-Secret' => $payment->client_secret])
        ->assertJsonPath('data.attributes.status', 'succeeded')
        ->assertJsonPath('data.attributes.receipt_id', 'sZrjQQ6btF')
        ->assertJsonPath('data.attributes.receipt_url', 'https://receipts.ru/sZrjQQ6btF');
});

test('чек без нашего заказа принимается, а с чужой подписью — нет', function () {
    $receipt = ['Id' => 'x', 'FiscalSign' => '1', 'Type' => 'Income', 'Url' => 'https://receipts.ru/x', 'InvoiceId' => 'pay_unknown'];

    ($this->notify)($receipt)->assertOk()->assertExactJson(['code' => 0]);

    $this->call('POST', "/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", $receipt, [], [], ['HTTP_CONTENT_HMAC' => 'bad'])
        ->assertStatus(401);
});

test('возврат принимает причину и возвращает её', function () {
    Http::fake(['api.cloudpayments.ru/*' => Http::response(['Success' => true, 'Model' => ['TransactionId' => 78]])]);
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id, 'amount' => 50000, 'amount_received' => 50000, 'currency' => 'RUB',
        'status' => PaymentStatus::Succeeded, 'attempt_count' => 1, 'connector' => 'cloudpayments',
    ]);
    $payment->paymentAttempts()->create(['connector' => 'cloudpayments', 'connector_transaction_id' => '77', 'status' => 'succeeded', 'amount' => 50000]);

    $this->postJson('/api/v1/refunds', ['payment_id' => $payment->key, 'amount' => 1000, 'reason' => 'Возврат остатка'], $this->headers)
        ->assertCreated()->assertJsonPath('data.attributes.reason', 'Возврат остатка');
});
