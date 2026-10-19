<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\WebhookEvent;
use Streeboga\PaymentData\Support\IdGenerator;

uses(RefreshDatabase::class);

/**
 * Бизнес-поля мерчанта (project_id, operation_id, order_id): принимаются при создании,
 * хранятся, возвращаются в ответе и в событиях платежа и возврата.
 */
beforeEach(function () {
    Queue::fake();

    $org = Organization::create(['name' => 'Org']);
    $merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $profile = BusinessProfile::create(['merchant_account_id' => $merchant->id, 'webhook_url' => 'https://merchant.example.com/webhook']);

    $rawKey = IdGenerator::apiKey('sandbox');
    ApiKey::create(['merchant_account_id' => $merchant->id, 'key_hash' => hash('sha256', $rawKey), 'key_prefix' => substr($rawKey, 0, 20), 'name' => 'Test']);
    $this->headers = ['api-key' => $rawKey];

    MerchantConnectorAccount::create([
        'merchant_account_id' => $merchant->id, 'business_profile_id' => $profile->id,
        'connector_name' => 'test', 'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test'],
        'payment_methods_enabled' => [['payment_method' => 'card']], 'test_mode' => true,
    ]);

    $this->paid = fn (array $extra = []) => $this->postJson('/api/v1/payments', $extra + [
        'amount' => 5000, 'currency' => 'RUB', 'confirm' => true, 'payment_method' => 'card',
        'payment_method_data' => ['card' => ['card_number' => '4242424242424242', 'card_exp_month' => '12', 'card_exp_year' => '2030', 'card_cvc' => '123']],
    ], $this->headers);
});

test('бизнес-поля возвращаются в ответе, в чтении и в событии платежа', function () {
    $identity = ['project_id' => 'hub', 'operation_id' => 'op-1', 'order_id' => 'inv-42'];

    $response = ($this->paid)($identity)->assertCreated();
    $response->assertJsonPath('data.attributes.project_id', 'hub')
        ->assertJsonPath('data.attributes.operation_id', 'op-1')
        ->assertJsonPath('data.attributes.order_id', 'inv-42');

    $this->getJson('/api/v1/payments?filter[status]=succeeded', $this->headers)
        ->assertJsonPath('data.0.attributes.order_id', 'inv-42');

    $content = WebhookEvent::query()->where('event_type', 'payment_succeeded')->sole()->content;
    expect(array_intersect_key($content, $identity))->toBe($identity);
});

test('бизнес-поля платежа едут в событии возврата', function () {
    $paymentId = ($this->paid)(['project_id' => 'hub', 'order_id' => 'inv-42'])->json('data.id');

    $this->postJson('/api/v1/refunds', ['payment_id' => $paymentId, 'amount' => 1000], $this->headers)->assertCreated();

    $content = WebhookEvent::query()->where('event_type', 'refund_succeeded')->sole()->content;
    expect($content['project_id'])->toBe('hub')
        ->and($content['order_id'])->toBe('inv-42')
        ->and($content['operation_id'])->toBeNull();
});

test('без бизнес-полей платёж создаётся, а в ответе и событии они null', function () {
    ($this->paid)()->assertCreated()->assertJsonPath('data.attributes.project_id', null);

    $content = WebhookEvent::query()->where('event_type', 'payment_succeeded')->sole()->content;
    expect($content)->toHaveKeys(['project_id', 'operation_id', 'order_id'])
        ->and($content['order_id'])->toBeNull();
});

test('бизнес-поле длиннее 128 символов отвергается', function () {
    ($this->paid)(['order_id' => str_repeat('x', 129)])->assertStatus(422);
});
