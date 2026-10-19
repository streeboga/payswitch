<?php

declare(strict_types=1);

use App\Jobs\DeliverWebhookJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\Refund;
use Streeboga\PaymentData\Models\WebhookEvent;
use Streeboga\PaymentData\Support\IdGenerator;
use Streeboga\PaymentData\Support\WebhookSigner;
use Tests\Helpers\ScriptedConnector;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $profile = BusinessProfile::create(['merchant_account_id' => $merchant->id]);

    $this->rawKey = IdGenerator::apiKey('sandbox');
    ApiKey::create([
        'merchant_account_id' => $merchant->id,
        'key_hash' => hash('sha256', $this->rawKey),
        'key_prefix' => substr($this->rawKey, 0, 20),
        'name' => 'Test',
    ]);

    ScriptedConnector::register('scripted');
    MerchantConnectorAccount::create([
        'merchant_account_id' => $merchant->id,
        'business_profile_id' => $profile->id,
        'connector_name' => 'scripted',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['api_key' => 'x'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    $this->paymentId = $this->postJson('/api/v1/payments', [
        'amount' => 10000, 'currency' => 'RUB', 'confirm' => true,
        'payment_method' => 'card',
        'payment_method_data' => ['card' => ['card_number' => '4242424242424242']],
    ], ['api-key' => $this->rawKey])->assertStatus(201)->json('data.id');
});

function refundEvents(): Builder
{
    return WebhookEvent::query()->where('event_type', 'like', 'refund_%');
}

function refundRequest(array $headers = []): TestResponse
{
    return test()->postJson('/api/v1/refunds', ['payment_id' => test()->paymentId, 'amount' => 4000], ['api-key' => test()->rawKey, ...$headers]);
}

test('таймаут PSP: возврат остаётся pending, 502 с ключом возврата, повтор с тем же ключом отдаёт его', function () {
    Log::spy();
    ScriptedConnector::$script['refund'] = new ConnectionException('cURL error 28: Operation timed out');

    $response = refundRequest(['Idempotency-Key' => 'refund-timeout']);

    $refund = Refund::sole();
    expect($refund->status->value)->toBe('pending');

    $response->assertStatus(502)
        ->assertJsonPath('errors.0.code', 'refund_pending')
        ->assertJsonPath('errors.0.meta.refund_id', $refund->key);

    Log::shouldHaveReceived('error')->withArgs(fn ($message, $context = []) => ($context['refund_id'] ?? null) === $refund->key)->once();
    expect(refundEvents()->count())->toBe(0);

    // Клиент повторяет — второго возврата у провайдера нет.
    refundRequest(['Idempotency-Key' => 'refund-timeout'])
        ->assertStatus(200)
        ->assertJsonPath('data.id', $refund->key)
        ->assertJsonPath('data.attributes.status', 'pending');

    expect(Refund::count())->toBe(1)
        ->and(ScriptedConnector::callsTo('refund'))->toHaveCount(1);
});

test('исключение, проглоченное драйвером (code=connector_error), тоже не делает возврат failed', function () {
    ScriptedConnector::$script['refund'] = ['success' => false, 'transaction_id' => null, 'message' => 'Connection refused', 'code' => 'connector_error'];

    refundRequest()->assertStatus(502)->assertJsonPath('errors.0.code', 'refund_pending');

    expect(Refund::sole()->status->value)->toBe('pending');
});

test('pending-возврат держит остаток: второй возврат сверх суммы отклоняется', function () {
    ScriptedConnector::$script['refund'] = new ConnectionException('timeout');
    refundRequest()->assertStatus(502);

    ScriptedConnector::$script['refund'] = ['success' => true, 'transaction_id' => 'r2', 'code' => 'ok'];
    $this->postJson('/api/v1/refunds', ['payment_id' => $this->paymentId, 'amount' => 6001], ['api-key' => $this->rawKey])
        ->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'refund_exceeds_payment');
});

test('явный отказ PSP: возврат failed, refund_failed создан и доставка поставлена', function () {
    Queue::fake();
    ScriptedConnector::$script['refund'] = ['success' => false, 'transaction_id' => null, 'message' => 'Insufficient balance', 'code' => 'refund_declined'];

    refundRequest()->assertStatus(502)->assertJsonPath('errors.0.code', 'refund_failed');

    $refund = Refund::sole();
    expect($refund->status->value)->toBe('failed')
        ->and($refund->error_code)->toBe('refund_declined');

    $event = refundEvents()->sole();
    expect($event->event_type)->toBe('refund_failed');
    Queue::assertPushed(DeliverWebhookJob::class, fn ($job) => $job->webhookEventId === $event->id);
});

test('успех: возврат succeeded и refund_succeeded', function () {
    Queue::fake();

    refundRequest()->assertStatus(201)->assertJsonPath('data.attributes.status', 'succeeded');

    expect(refundEvents()->sole()->event_type)->toBe('refund_succeeded');
    Queue::assertPushed(DeliverWebhookJob::class);
});

test('провайдер вызывается вне транзакции с блокировкой платежа и получает ключ возврата', function () {
    $baseLevel = DB::transactionLevel();
    $levelAtPsp = null;
    ScriptedConnector::$script['refund'] = function () use (&$levelAtPsp) {
        $levelAtPsp = DB::transactionLevel();

        return ['success' => true, 'transaction_id' => 'r1', 'code' => 'ok'];
    };

    refundRequest()->assertStatus(201);

    expect($levelAtPsp)->toBe($baseLevel)
        ->and(ScriptedConnector::callsTo('refund')[0]['refund_id'])->toBe(Refund::sole()->key);
});

test('событие возврата несёт ключ идемпотентности вызывающего, ответ и чтение — тоже; подпись покрывает поле', function () {
    Queue::fake();

    $refundId = refundRequest(['Idempotency-Key' => 'cn_abc_refund'])
        ->assertStatus(201)
        ->assertJsonPath('data.attributes.idempotency_key', 'cn_abc_refund')
        ->json('data.id');

    $this->getJson("/api/v1/refunds/{$refundId}", ['api-key' => $this->rawKey])
        ->assertOk()
        ->assertJsonPath('data.attributes.idempotency_key', 'cn_abc_refund');

    $event = refundEvents()->sole();
    expect($event->content)->toMatchArray(['refund_id' => $refundId, 'idempotency_key' => 'cn_abc_refund']);

    $profile = BusinessProfile::sole();
    $profile->update(['webhook_url' => 'https://merchant.example.com/webhook']);
    Http::fake(['*' => Http::response('ok', 200)]);
    app()->call([new DeliverWebhookJob($event->id), 'handle']);

    Http::assertSent(function ($request) use ($profile) {
        $key = $profile->refresh()->payment_response_hash_key;
        $body = $request->body();
        $tampered = str_replace('cn_abc_refund', 'cn_xyz_refund', $body);

        return json_decode($body, true)['content']['idempotency_key'] === 'cn_abc_refund'
            && WebhookSigner::verify($body, $request->header('x-webhook-signature-512')[0], $key)
            && hash_equals(WebhookSigner::signWithTimestamp($body, $key, (int) $request->header('x-webhook-timestamp')[0]), $request->header('x-webhook-signature')[0])
            && ! WebhookSigner::verify($tampered, $request->header('x-webhook-signature-512')[0], $key);
    });
});

test('refund_failed тоже несёт ключ; возврат без ключа — поле есть и оно null', function () {
    Queue::fake();
    ScriptedConnector::$script['refund'] = ['success' => false, 'transaction_id' => null, 'message' => 'Insufficient balance', 'code' => 'refund_declined'];
    refundRequest(['Idempotency-Key' => 'cn_fail_refund'])->assertStatus(502);

    ScriptedConnector::$script['refund'] = ['success' => true, 'transaction_id' => 'r2', 'code' => 'ok'];
    refundRequest()->assertStatus(201)->assertJsonPath('data.attributes.idempotency_key', null);

    $events = refundEvents()->orderBy('id')->get();
    expect($events[0]->event_type)->toBe('refund_failed')
        ->and($events[0]->content['idempotency_key'])->toBe('cn_fail_refund')
        ->and($events[1]->event_type)->toBe('refund_succeeded')
        ->and(array_key_exists('idempotency_key', $events[1]->content))->toBeTrue()
        ->and($events[1]->content['idempotency_key'])->toBeNull();
});

test('причина и состав чека возврата доходят до коннектора; чек не на сумму возврата — 422', function () {
    $receipt = ['taxation_system' => 'usn_income', 'items' => [[
        'label' => 'Товар', 'quantity' => 1, 'price' => 4000, 'amount' => 4000,
        'vat' => 'none', 'payment_method' => 'full_payment', 'payment_object' => 'commodity',
    ]]];
    $body = ['payment_id' => $this->paymentId, 'amount' => 4000, 'reason' => 'Отказ от товара', 'receipt' => $receipt];

    $this->postJson('/api/v1/refunds', ['amount' => 3999] + $body, ['api-key' => $this->rawKey])
        ->assertStatus(422)->assertJsonPath('errors.0.source.pointer', '/receipt/items');
    $this->postJson('/api/v1/refunds', $body, ['api-key' => $this->rawKey])->assertCreated();

    expect(ScriptedConnector::callsTo('refund'))->toHaveCount(1)
        ->and(ScriptedConnector::callsTo('refund')[0])->toMatchArray(['reason' => 'Отказ от товара', 'receipt' => $receipt]);
});
