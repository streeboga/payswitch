<?php

declare(strict_types=1);

use App\Http\Resources\PaymentIntentResource;
use App\Services\DashboardPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\Refund;
use Streeboga\PaymentData\Support\IdGenerator;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| ПЛ-1…ПЛ-6: список платежей и возвратов по ключу сервиса
|--------------------------------------------------------------------------
*/

function listTestMerchant(string $name): array
{
    $org = Organization::create(['name' => "Org {$name}"]);
    $merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => $name]);
    BusinessProfile::create(['merchant_account_id' => $merchant->id]);

    $rawKey = IdGenerator::apiKey('sandbox');
    ApiKey::create([
        'merchant_account_id' => $merchant->id,
        'key_hash' => hash('sha256', $rawKey),
        'key_prefix' => substr($rawKey, 0, 20),
        'name' => "Key {$name}",
    ]);

    return [$merchant, $rawKey];
}

function listTestPayment(MerchantAccount $merchant, array $attributes = []): PaymentIntent
{
    return PaymentIntent::create(array_merge([
        'key' => IdGenerator::paymentId(),
        'merchant_account_id' => $merchant->id,
        'amount' => 1000,
        'currency' => 'RUB',
        'status' => 'succeeded',
        'capture_method' => 'automatic',
    ], $attributes));
}

beforeEach(function () {
    [$this->merchantA, $this->keyA] = listTestMerchant('A');
    [$this->merchantB, $this->keyB] = listTestMerchant('B');
});

test('service key returns only its own merchant payments', function () {
    listTestPayment($this->merchantA, ['amount' => 1111]);
    listTestPayment($this->merchantA, ['amount' => 2222]);
    listTestPayment($this->merchantB, ['amount' => 9999]);

    $response = $this->getJson('/api/v1/payments', ['api-key' => $this->keyA]);

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertJsonPath('data.0.type', 'payments')
        ->assertJsonCount(2, 'data');

    expect(collect($response->json('data.*.attributes.amount'))->sort()->values()->all())
        ->toBe([1111, 2222]);
});

test('merchant_id in the query cannot point a key at another merchant', function () {
    listTestPayment($this->merchantA, ['amount' => 1111]);
    listTestPayment($this->merchantB, ['amount' => 9999]);

    $response = $this->getJson(
        "/api/v1/payments?merchant_id={$this->merchantB->id}",
        ['api-key' => $this->keyA],
    );

    $response->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.attributes.amount'))->toBe(1111);
});

test('no api key is 401', function () {
    $this->getJson('/api/v1/payments')->assertStatus(401);
});

test('publishable key is refused', function () {
    $this->getJson('/api/v1/payments', ['api-key' => $this->merchantA->publishable_key])
        ->assertStatus(403);
});

test('filters and sorting are the dashboard ones', function () {
    listTestPayment($this->merchantA, ['amount' => 100, 'status' => 'succeeded', 'currency' => 'RUB']);
    listTestPayment($this->merchantA, ['amount' => 500, 'status' => 'failed', 'currency' => 'RUB']);
    listTestPayment($this->merchantA, ['amount' => 900, 'status' => 'succeeded', 'currency' => 'USD']);

    $byStatus = $this->getJson('/api/v1/payments?filter[status]=succeeded', ['api-key' => $this->keyA]);
    $byStatus->assertOk()->assertJsonCount(2, 'data');

    $byCurrency = $this->getJson('/api/v1/payments?filter[currency]=USD', ['api-key' => $this->keyA]);
    $byCurrency->assertOk()->assertJsonCount(1, 'data');

    $byAmount = $this->getJson('/api/v1/payments?filter[amount_min]=400&filter[amount_max]=600', ['api-key' => $this->keyA]);
    $byAmount->assertOk()->assertJsonCount(1, 'data');
    expect($byAmount->json('data.0.attributes.amount'))->toBe(500);

    $sorted = $this->getJson('/api/v1/payments?sort=amount', ['api-key' => $this->keyA]);
    expect($sorted->json('data.*.attributes.amount'))->toBe([100, 500, 900]);
});

test('sort outside the whitelist and oversized page are rejected', function () {
    $this->getJson('/api/v1/payments?sort=amount_desc', ['api-key' => $this->keyA])->assertStatus(422);
    $this->getJson('/api/v1/payments?page[size]=1000', ['api-key' => $this->keyA])->assertStatus(422);
});

test('pagination reports pages and honours page size', function () {
    foreach (range(1, 5) as $i) {
        listTestPayment($this->merchantA, ['amount' => $i * 100]);
    }

    $page1 = $this->getJson('/api/v1/payments?page[size]=2&page[number]=1', ['api-key' => $this->keyA]);
    $page2 = $this->getJson('/api/v1/payments?page[size]=2&page[number]=2', ['api-key' => $this->keyA]);

    $page1->assertOk()->assertJsonCount(2, 'data');
    $page2->assertOk()->assertJsonCount(2, 'data');

    expect($page1->json('meta'))->toHaveKeys(['total', 'last_page'])
        ->and($page1->json('meta.total'))->toBe(5)
        ->and($page1->json('data.0.id'))->not->toBe($page2->json('data.0.id'));
});

test('merchant list and dashboard list agree on the shape of a payment', function () {
    $payment = listTestPayment($this->merchantA, ['amount' => 4242]);

    $merchantView = $this->getJson('/api/v1/payments', ['api-key' => $this->keyA])
        ->assertOk()->json('data.0');

    $dashboardView = app(DashboardPaymentService::class)
        ->list($this->merchantA->id, [], 20)
        ->items();

    $dashboardShape = (new PaymentIntentResource($dashboardView[0]))
        ->toResponse(request())->getData(true)['data'];

    expect(array_keys($merchantView['attributes']))
        ->toBe(array_keys($dashboardShape['attributes']))
        ->and($merchantView['id'])->toBe($payment->key);
});

test('csv export is not exposed to service keys', function () {
    $this->getJson('/api/v1/payments/export', ['api-key' => $this->keyA])->assertStatus(404);
});

test('refund list is scoped by merchant and filtered by payment key', function () {
    $paymentA1 = listTestPayment($this->merchantA);
    $paymentA2 = listTestPayment($this->merchantA);
    $paymentB = listTestPayment($this->merchantB);

    foreach ([[$paymentA1, 100], [$paymentA2, 200], [$paymentB, 300]] as [$payment, $amount]) {
        Refund::create([
            'key' => IdGenerator::refundId(),
            'merchant_account_id' => $payment->merchant_account_id,
            'payment_intent_id' => $payment->id,
            'amount' => $amount,
            'currency' => 'RUB',
            'status' => 'succeeded',
        ]);
    }

    $this->getJson('/api/v1/refunds', ['api-key' => $this->keyA])
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $scoped = $this->getJson("/api/v1/refunds?filter[payment_id]={$paymentA1->key}", ['api-key' => $this->keyA]);
    $scoped->assertOk()->assertJsonCount(1, 'data');
    expect($scoped->json('data.0.attributes.amount'))->toBe(100);

    // Чужой платёж по ключу своего мерчанта — пусто, а не чужой возврат
    $this->getJson("/api/v1/refunds?filter[payment_id]={$paymentB->key}", ['api-key' => $this->keyA])
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
