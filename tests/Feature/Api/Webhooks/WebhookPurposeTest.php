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
 * Назначение платежа доезжает до приёмника.
 *
 * Без metadata в теле события получатель не отличит пополнение кошелька от
 * оплаты счёта: тип события описывает судьбу платежа, а не то, чьи это деньги.
 */
beforeEach(function () {
    Queue::fake();

    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->profile = BusinessProfile::create([
        'merchant_account_id' => $this->merchant->id,
        'webhook_url' => 'https://merchant.example.com/webhook',
    ]);

    $this->rawKey = IdGenerator::apiKey('sandbox');
    ApiKey::create([
        'merchant_account_id' => $this->merchant->id,
        'key_hash' => hash('sha256', $this->rawKey),
        'key_prefix' => substr($this->rawKey, 0, 20),
        'name' => 'Test',
    ]);

    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'test',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);
});

function payWith(array $metadata): void
{
    test()->postJson('/api/v1/payments', [
        'amount' => 5000, 'currency' => 'RUB', 'confirm' => true,
        'payment_method' => 'card',
        'payment_method_data' => ['card' => ['card_number' => '4242424242424242', 'card_exp_month' => '12', 'card_exp_year' => '2030', 'card_cvc' => '123']],
        'metadata' => $metadata,
    ], ['api-key' => test()->rawKey]);
}

test('назначение платежа едет в теле события', function () {
    payWith(['purpose' => 'wallet_deposit', 'genesis_customer_id' => 'hub-user-7']);

    $event = WebhookEvent::query()->where('event_type', 'payment_succeeded')->latest('id')->firstOrFail();

    expect($event->content['metadata']['purpose'])->toBe('wallet_deposit')
        ->and($event->content['metadata']['genesis_customer_id'])->toBe('hub-user-7');
});

test('назначение счёта отличимо от назначения кошелька', function () {
    payWith(['purpose' => 'invoice', 'invoice_key' => 'inv_42']);

    $event = WebhookEvent::query()->where('event_type', 'payment_succeeded')->latest('id')->firstOrFail();

    expect($event->content['metadata']['purpose'])->toBe('invoice');
});

test('в теле события есть подтверждённая провайдером сумма', function () {
    payWith(['purpose' => 'wallet_deposit']);

    $event = WebhookEvent::query()->where('event_type', 'payment_succeeded')->latest('id')->firstOrFail();

    expect($event->content)->toHaveKey('amount_received')
        ->and($event->content['amount_received'])->toBe(5000);
});

test('платёж без назначения даёт пустую metadata, а не отсутствующий ключ', function () {
    payWith([]);

    $event = WebhookEvent::query()->where('event_type', 'payment_succeeded')->latest('id')->firstOrFail();

    expect($event->content)->toHaveKey('metadata')
        ->and($event->content['metadata'])->toBe([]);
});
