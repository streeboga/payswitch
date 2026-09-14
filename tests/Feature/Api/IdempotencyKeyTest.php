<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentAttempt;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\Refund;
use Streeboga\PaymentData\Support\IdGenerator;

uses(RefreshDatabase::class);

/**
 * Мерчант с тестовым коннектором и секретным ключом; возвращает сырой ключ.
 */
function idempotencyMerchant(string $name): string
{
    $org = Organization::create(['name' => "Org {$name}"]);
    $merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => $name]);
    $profile = BusinessProfile::create(['merchant_account_id' => $merchant->id]);

    $rawKey = IdGenerator::apiKey('sandbox');
    ApiKey::create([
        'merchant_account_id' => $merchant->id,
        'key_hash' => hash('sha256', $rawKey),
        'key_prefix' => substr($rawKey, 0, 20),
        'name' => 'Test',
    ]);

    MerchantConnectorAccount::create([
        'merchant_account_id' => $merchant->id,
        'business_profile_id' => $profile->id,
        'connector_name' => 'test',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    return $rawKey;
}

function idempotentPaymentBody(array $overrides = []): array
{
    return array_merge([
        'amount' => 6540,
        'currency' => 'RUB',
        'confirm' => true,
        'payment_method' => 'card',
        'payment_method_data' => ['card' => ['card_number' => '4242424242424242', 'card_exp_month' => '12', 'card_exp_year' => '2030', 'card_cvc' => '123']],
    ], $overrides);
}

beforeEach(function () {
    $this->keyA = idempotencyMerchant('A');
    $this->keyB = idempotencyMerchant('B');
});

// --- Платёж ---

test('повтор создания платежа с тем же ключом возвращает тот же платёж без второго похода к PSP', function () {
    $headers = ['api-key' => $this->keyA, 'Idempotency-Key' => 'order-1'];

    $first = $this->postJson('/api/v1/payments', idempotentPaymentBody(), $headers);
    $first->assertStatus(201)->assertJsonPath('data.attributes.status', 'succeeded');

    $second = $this->postJson('/api/v1/payments', idempotentPaymentBody(), $headers);
    $second->assertStatus(200)
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.id', $first->json('data.id'))
        ->assertJsonPath('data.attributes.status', 'succeeded');

    expect(PaymentIntent::count())->toBe(1)
        ->and(PaymentAttempt::count())->toBe(1);
});

test('без ключа идемпотентности каждый POST создаёт новый платёж', function () {
    $this->postJson('/api/v1/payments', idempotentPaymentBody(['confirm' => false]), ['api-key' => $this->keyA])->assertStatus(201);
    $this->postJson('/api/v1/payments', idempotentPaymentBody(['confirm' => false]), ['api-key' => $this->keyA])->assertStatus(201);

    expect(PaymentIntent::count())->toBe(2);
});

test('ключ мерчанта A у мерчанта B создаёт свой платёж, а не отдаёт чужой', function () {
    $a = $this->postJson('/api/v1/payments', idempotentPaymentBody(), ['api-key' => $this->keyA, 'Idempotency-Key' => 'shared-key']);
    $b = $this->postJson('/api/v1/payments', idempotentPaymentBody(), ['api-key' => $this->keyB, 'Idempotency-Key' => 'shared-key']);

    $a->assertStatus(201);
    $b->assertStatus(201);

    expect($b->json('data.id'))->not->toBe($a->json('data.id'))
        ->and(PaymentIntent::count())->toBe(2);
});

test('тот же ключ с другой суммой или валютой — 422 idempotency_key_reused', function (array $changed) {
    $headers = ['api-key' => $this->keyA, 'Idempotency-Key' => 'order-2'];

    $this->postJson('/api/v1/payments', idempotentPaymentBody(), $headers)->assertStatus(201);

    $this->postJson('/api/v1/payments', idempotentPaymentBody($changed), $headers)
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'idempotency_key_reused');

    expect(PaymentIntent::count())->toBe(1);
})->with([
    'сумма' => [['amount' => 100]],
    'валюта' => [['currency' => 'USD']],
]);

test('ключ длиннее 255 символов отклоняется валидацией', function () {
    $this->postJson('/api/v1/payments', idempotentPaymentBody(), ['api-key' => $this->keyA, 'Idempotency-Key' => str_repeat('k', 256)])
        ->assertStatus(422);

    expect(PaymentIntent::count())->toBe(0);
});

test('гонка: параллельный запрос вставил платёж с тем же ключом первым — отдаётся его платёж', function () {
    // Имитация второго запроса, который успел вставить строку между нашим поиском и нашей вставкой.
    $raced = null;
    PaymentIntent::creating(function (PaymentIntent $model) use (&$raced) {
        if ($raced !== null || $model->idempotency_key === null) {
            return;
        }
        $raced = $model->replicate();
        $raced->key = IdGenerator::paymentId();
        $raced->client_secret = IdGenerator::clientSecret($raced->key);
        $raced->saveQuietly();
    });

    $response = $this->postJson('/api/v1/payments', idempotentPaymentBody(['confirm' => false]), ['api-key' => $this->keyA, 'Idempotency-Key' => 'race-1']);

    $response->assertStatus(200)->assertJsonPath('data.id', $raced->key);
    expect(PaymentIntent::count())->toBe(1);
});

// --- Возврат ---

function idempotencyPaidPayment(string $apiKey): string
{
    return test()->postJson('/api/v1/payments', idempotentPaymentBody(), ['api-key' => $apiKey])
        ->assertStatus(201)
        ->json('data.id');
}

test('повтор возврата с тем же ключом возвращает тот же возврат', function () {
    $paymentId = idempotencyPaidPayment($this->keyA);
    $headers = ['api-key' => $this->keyA, 'Idempotency-Key' => 'refund-1'];

    $first = $this->postJson('/api/v1/refunds', ['payment_id' => $paymentId, 'amount' => 3000], $headers);
    $first->assertStatus(201);

    $second = $this->postJson('/api/v1/refunds', ['payment_id' => $paymentId, 'amount' => 3000], $headers);
    $second->assertStatus(200)
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.id', $first->json('data.id'));

    expect(Refund::count())->toBe(1);
});

test('тот же ключ возврата с другой суммой или другим платежом — 422', function () {
    $paymentId = idempotencyPaidPayment($this->keyA);
    $otherPaymentId = idempotencyPaidPayment($this->keyA);
    $headers = ['api-key' => $this->keyA, 'Idempotency-Key' => 'refund-2'];

    $this->postJson('/api/v1/refunds', ['payment_id' => $paymentId, 'amount' => 1000], $headers)->assertStatus(201);

    $this->postJson('/api/v1/refunds', ['payment_id' => $paymentId, 'amount' => 2000], $headers)
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'idempotency_key_reused');

    $this->postJson('/api/v1/refunds', ['payment_id' => $otherPaymentId, 'amount' => 1000], $headers)
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'idempotency_key_reused');

    expect(Refund::count())->toBe(1);
});

test('ключ возврата мерчанта A у мерчанта B создаёт свой возврат', function () {
    $paymentA = idempotencyPaidPayment($this->keyA);
    $paymentB = idempotencyPaidPayment($this->keyB);

    $a = $this->postJson('/api/v1/refunds', ['payment_id' => $paymentA, 'amount' => 1000], ['api-key' => $this->keyA, 'Idempotency-Key' => 'refund-shared']);
    $b = $this->postJson('/api/v1/refunds', ['payment_id' => $paymentB, 'amount' => 1000], ['api-key' => $this->keyB, 'Idempotency-Key' => 'refund-shared']);

    $a->assertStatus(201);
    $b->assertStatus(201);

    expect($b->json('data.id'))->not->toBe($a->json('data.id'))
        ->and(Refund::count())->toBe(2);
});
