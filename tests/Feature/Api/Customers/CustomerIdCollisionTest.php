<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\Customer;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Support\IdGenerator;

uses(RefreshDatabase::class);

function customerCollisionApiKey(string $name): string
{
    $org = Organization::create(['name' => "Org {$name}"]);
    $merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => $name]);
    $rawKey = IdGenerator::apiKey('sandbox');
    ApiKey::create([
        'merchant_account_id' => $merchant->id,
        'key_hash' => hash('sha256', $rawKey),
        'key_prefix' => substr($rawKey, 0, 20),
        'name' => 'Test',
    ]);

    return $rawKey;
}

// П12: id клиента, заданный мерчантом, и есть customers.key — публичный ключ в маршрутах
// и в payment_intents.customer_id, уникальный глобально. Занятый другим мерчантом id
// давал 500 на нарушении уникальности.
test('id клиента, занятый другим мерчантом, — 409 duplicate_customer_id, а не 500', function () {
    $this->postJson('/api/v1/customers', ['id' => 'user-42', 'name' => 'A'], ['api-key' => customerCollisionApiKey('A')])
        ->assertStatus(201);

    $this->postJson('/api/v1/customers', ['id' => 'user-42', 'name' => 'B'], ['api-key' => customerCollisionApiKey('B')])
        ->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'duplicate_customer_id');

    expect(Customer::count())->toBe(1);
});
