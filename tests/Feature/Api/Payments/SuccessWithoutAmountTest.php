<?php

declare(strict_types=1);

use App\Support\ProviderAmount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Streeboga\PaymentConnectors\Drivers\CloudPaymentsConnector;
use Streeboga\PaymentConnectors\Drivers\StripeConnector;
use Streeboga\PaymentConnectors\Drivers\YooKassaConnector;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\WebhookEvent;
use Streeboga\PaymentData\Support\IdGenerator;
use Tests\Helpers\ScriptedConnector;

uses(RefreshDatabase::class);

/**
 * Провайдер ответил «успех», не назвав суммы, — платёж не оплачен: processing, пока
 * сумму не подтвердит уведомление или sync. Правило одно на три входа.
 */
beforeEach(function () {
    Queue::fake();
    ScriptedConnector::register('scripted');

    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    $rawKey = IdGenerator::apiKey('sandbox');
    ApiKey::create(['merchant_account_id' => $this->merchant->id, 'key_hash' => hash('sha256', $rawKey), 'key_prefix' => substr($rawKey, 0, 20), 'name' => 'Test']);
    $this->headers = ['api-key' => $rawKey];

    $this->mca = fn (string $connector) => MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id, 'business_profile_id' => $this->profile->id,
        'connector_name' => $connector, 'connector_type' => 'fiz_operations',
        'connector_account_details' => ['api_key' => 'k'],
        'payment_methods_enabled' => [['payment_method' => 'card']], 'test_mode' => true,
    ]);

    $this->waiting = fn (string $connector, PaymentStatus $status = PaymentStatus::RequiresCustomerAction) => tap(PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id, 'business_profile_id' => $this->profile->id,
        'amount' => 10000, 'currency' => 'RUB', 'status' => $status, 'connector' => $connector,
    ]), fn (PaymentIntent $p) => $p->paymentAttempts()->create(['connector' => $connector, 'connector_transaction_id' => 'txn_1', 'status' => 'requires_action', 'amount' => 10000]));

    $this->events = fn (PaymentIntent $payment) => WebhookEvent::query()->where('payment_intent_id', $payment->id)->orderBy('id')->get()
        ->map(fn (WebhookEvent $e) => [$e->event_type, $e->content['status'], $e->content['amount_received']])->all();
});

test('синхронный успех без суммы — processing, а не оплата', function () {
    ($this->mca)('scripted');
    ScriptedConnector::$script['purchase'] = ['success' => true, 'transaction_id' => 'ch_1', 'code' => 'ok', 'message' => 'ok', 'data' => []];

    $response = $this->postJson('/api/v1/payments', [
        'amount' => 10000, 'currency' => 'RUB', 'confirm' => true, 'payment_method' => 'card',
        'payment_method_data' => ['card' => ['card_number' => '4242424242424242']],
    ], $this->headers)->assertCreated();

    $response->assertJsonPath('data.attributes.status', 'processing')
        ->assertJsonPath('data.attributes.amount_received', null)
        ->assertJsonPath('data.attributes.error_code', 'amount_unconfirmed');

    expect(($this->events)(PaymentIntent::sole()))->toBe([['payment_status_changed', 'processing', null]]);
});

test('синхронный успех с чужой суммой — на разбор мерчанту', function () {
    ($this->mca)('scripted');
    ScriptedConnector::$script['purchase'] = ['success' => true, 'transaction_id' => 'ch_1', 'code' => 'ok', 'message' => 'ok', 'data' => ['amount' => 100]];

    $this->postJson('/api/v1/payments', [
        'amount' => 10000, 'currency' => 'RUB', 'confirm' => true, 'payment_method' => 'card',
        'payment_method_data' => ['card' => ['card_number' => '4242424242424242']],
    ], $this->headers)->assertCreated()
        ->assertJsonPath('data.attributes.status', 'requires_merchant_action')
        ->assertJsonPath('data.attributes.error_code', 'amount_mismatch');
});

test('уведомление об успехе без суммы — processing; уведомление с суммой — оплачено', function () {
    $mca = ($this->mca)('test');
    $payment = ($this->waiting)('test');
    $url = "/api/v1/webhooks/{$this->merchant->key}/{$mca->key}";

    $this->postJson($url, ['type' => 'payment.succeeded', 'payment_id' => $payment->key])->assertOk();
    expect($payment->refresh()->status)->toBe(PaymentStatus::Processing)
        ->and($payment->error_code)->toBe('amount_unconfirmed')
        ->and($payment->amount_received)->toBeNull();

    // Повтор без суммы ничего не двигает и второго события не шлёт.
    $this->postJson($url, ['type' => 'payment.succeeded', 'payment_id' => $payment->key])->assertOk();

    $this->postJson($url, ['type' => 'payment.succeeded', 'payment_id' => $payment->key, 'amount' => 10000])->assertOk();
    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->amount_received)->toBe(10000)
        ->and($payment->error_code)->toBeNull();

    expect(($this->events)($payment))->toBe([
        ['payment_status_changed', 'processing', null],
        ['payment_succeeded', 'succeeded', 10000],
    ]);
});

test('успех без суммы по истёкшему платежу — на разбор мерчанту, а не в оплату', function () {
    $mca = ($this->mca)('test');
    $payment = ($this->waiting)('test', PaymentStatus::Expired);

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$mca->key}", ['type' => 'payment.succeeded', 'payment_id' => $payment->key])->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::RequiresMerchantAction)
        ->and($payment->error_code)->toBe('amount_unconfirmed');
});

test('sync: успех без суммы — processing, с суммой — оплачено, с чужой — на разбор', function (array $data, string $status, ?int $received, ?string $error) {
    ($this->mca)('scripted');
    $payment = ($this->waiting)('scripted');
    ScriptedConnector::$script['getPaymentStatus'] = ['success' => true, 'transaction_id' => 'txn_1', 'code' => 'ok', 'message' => 'ok', 'data' => ['status' => 'succeeded'] + $data];

    $this->postJson("/api/v1/payments/{$payment->key}/sync", [], $this->headers)->assertOk()
        ->assertJsonPath('data.attributes.status', $status)
        ->assertJsonPath('data.attributes.amount_received', $received)
        ->assertJsonPath('data.attributes.error_code', $error);
})->with([
    'без суммы' => [[], 'processing', null, 'amount_unconfirmed'],
    'с суммой' => [['amount' => 10000], 'succeeded', 10000, null],
    'с чужой суммой' => [['amount' => 9000], 'requires_merchant_action', null, 'amount_mismatch'],
]);

test('sync подтверждает сумму платежу, который её ждал', function () {
    ($this->mca)('scripted');
    $payment = ($this->waiting)('scripted', PaymentStatus::Processing);
    $payment->update(['error_code' => 'amount_unconfirmed']);
    ScriptedConnector::$script['getPaymentStatus'] = ['success' => true, 'transaction_id' => 'txn_1', 'code' => 'ok', 'message' => 'ok', 'data' => ['status' => 'succeeded', 'amount' => 10000]];

    $this->postJson("/api/v1/payments/{$payment->key}/sync", [], $this->headers)->assertOk()
        ->assertJsonPath('data.attributes.status', 'succeeded')
        ->assertJsonPath('data.attributes.amount_received', 10000)
        ->assertJsonPath('data.attributes.error_code', null);
});

test('сумма читается там, где её кладёт провайдер, и в его единицах', function (string $driver, array $data, ?int $minor) {
    expect(ProviderAmount::minor(new $driver([]), $data))->toBe($minor);
})->with([
    'CloudPayments: рубли на верхнем уровне' => [CloudPaymentsConnector::class, ['Amount' => 150.5], 15050],
    'YooKassa: объект суммы в object' => [YooKassaConnector::class, ['event' => 'payment.succeeded', 'object' => ['amount' => ['value' => '99.90', 'currency' => 'RUB']]], 9990],
    'YooKassa: ответ API' => [YooKassaConnector::class, ['status' => 'succeeded', 'amount' => ['value' => '10.00', 'currency' => 'RUB']], 1000],
    'Stripe: минорные в data.object' => [StripeConnector::class, ['type' => 'payment_intent.succeeded', 'data' => ['object' => ['amount' => 2500]]], 2500],
    'не названа' => [StripeConnector::class, ['type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_1']]], null],
    'не число' => [CloudPaymentsConnector::class, ['Amount' => 'много'], null],
]);
