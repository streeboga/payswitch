<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
 * Захват и отмена с Idempotency-Key: повтор отдаёт прежний результат, а не ошибку
 * перехода и не второе действие у провайдера.
 */
beforeEach(function () {
    Queue::fake();
    ScriptedConnector::register('scripted');

    $org = Organization::create(['name' => 'Org']);
    $merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $profile = BusinessProfile::create(['merchant_account_id' => $merchant->id]);

    $rawKey = IdGenerator::apiKey('sandbox');
    ApiKey::create(['merchant_account_id' => $merchant->id, 'key_hash' => hash('sha256', $rawKey), 'key_prefix' => substr($rawKey, 0, 20), 'name' => 'Test']);
    $this->headers = ['api-key' => $rawKey];

    MerchantConnectorAccount::create([
        'merchant_account_id' => $merchant->id, 'business_profile_id' => $profile->id,
        'connector_name' => 'scripted', 'connector_type' => 'fiz_operations',
        'connector_account_details' => ['api_key' => 'k'], 'test_mode' => true,
    ]);

    // Платёж с холдом на 10 000.
    $this->payment = PaymentIntent::create([
        'merchant_account_id' => $merchant->id, 'business_profile_id' => $profile->id,
        'amount' => 10000, 'amount_capturable' => 10000, 'currency' => 'RUB',
        'status' => PaymentStatus::RequiresCapture, 'capture_method' => 'manual', 'connector' => 'scripted',
    ]);
    $this->payment->paymentAttempts()->create(['connector' => 'scripted', 'connector_transaction_id' => 'txn_1', 'status' => 'succeeded', 'amount' => 10000]);

    $this->act = fn (string $action, array $body = [], ?string $key = null) => $this->postJson(
        "/api/v1/payments/{$this->payment->key}/{$action}", $body, $this->headers + ($key ? ['Idempotency-Key' => $key] : []),
    );
});

test('повтор частичного захвата с тем же ключом не списывает второй раз', function () {
    ($this->act)('capture', ['amount_to_capture' => 4000], 'cap-1')
        ->assertOk()->assertHeaderMissing('Idempotent-Replayed')
        ->assertJsonPath('data.attributes.amount_received', 4000);

    ($this->act)('capture', ['amount_to_capture' => 4000], 'cap-1')
        ->assertOk()->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.attributes.amount_received', 4000)
        ->assertJsonPath('data.attributes.amount_capturable', 6000);

    expect(ScriptedConnector::callsTo('capture'))->toHaveCount(1);
});

test('повтор полного захвата отдаёт succeeded, а не ошибку перехода, и событие одно', function () {
    ($this->act)('capture', ['amount_to_capture' => 10000], 'cap-full')->assertOk();
    ($this->act)('capture', ['amount_to_capture' => 10000], 'cap-full')
        ->assertOk()->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.attributes.status', 'succeeded');

    expect(WebhookEvent::query()->where('payment_intent_id', $this->payment->id)->count())->toBe(1);
});

test('тот же ключ с другой суммой или другим действием — 422 idempotency_key_reused', function () {
    ($this->act)('capture', ['amount_to_capture' => 4000], 'k')->assertOk();

    ($this->act)('capture', ['amount_to_capture' => 5000], 'k')->assertStatus(422)->assertJsonPath('errors.0.code', 'idempotency_key_reused');
    ($this->act)('cancel', [], 'k')->assertStatus(422)->assertJsonPath('errors.0.code', 'idempotency_key_reused');

    expect(ScriptedConnector::callsTo('capture'))->toHaveCount(1);
});

test('другой ключ — новый захват', function () {
    ($this->act)('capture', ['amount_to_capture' => 4000], 'a')->assertOk();
    ($this->act)('capture', ['amount_to_capture' => 4000], 'b')->assertOk()->assertJsonPath('data.attributes.amount_received', 8000);

    expect(ScriptedConnector::callsTo('capture'))->toHaveCount(2);
});

test('повтор отмены с тем же ключом отдаёт cancelled и не отменяет холд второй раз', function () {
    ($this->act)('cancel', [], 'void-1')->assertOk()->assertJsonPath('data.attributes.status', 'cancelled');
    ($this->act)('cancel', [], 'void-1')
        ->assertOk()->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.attributes.status', 'cancelled');

    expect(ScriptedConnector::callsTo('void'))->toHaveCount(1);
});

test('неудавшийся захват ключ не занимает: повтор идёт к провайдеру снова', function () {
    ScriptedConnector::$script['capture'] = ['success' => false, 'transaction_id' => null, 'message' => 'timeout', 'code' => 'connector_error'];
    ($this->act)('capture', ['amount_to_capture' => 10000], 'retry')->assertStatus(502);

    unset(ScriptedConnector::$script['capture']);
    ($this->act)('capture', ['amount_to_capture' => 10000], 'retry')
        ->assertOk()->assertHeaderMissing('Idempotent-Replayed')
        ->assertJsonPath('data.attributes.status', 'succeeded');
});

test('захват передаёт провайдеру payment_id — основу его собственного ключа идемпотентности', function () {
    ($this->act)('capture', ['amount_to_capture' => 10000])->assertOk();

    expect(ScriptedConnector::callsTo('capture')[0]['payment_id'])->toBe($this->payment->key);
});

test('без ключа поведение прежнее: повторная отмена — ошибка перехода', function () {
    ($this->act)('cancel')->assertOk();
    ($this->act)('cancel')->assertStatus(400);
});
