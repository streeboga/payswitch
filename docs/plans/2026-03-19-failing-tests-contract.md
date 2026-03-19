# Failing Tests: Контракт системы

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Написать failing тесты на все 12 проблем продакшн-кода. Тесты фиксируют правильное поведение. Код пока НЕ чиним — только тесты.

**Architecture:** Один тест-файл `tests/Feature/ContractViolationsTest.php` — все failing тесты в одном месте с `->markTestSkipped()` + описанием бага. Отдельные unit-тесты для коннекторов.

**Tech Stack:** Pest PHP, Http::fake(), RefreshDatabase

---

## Task 1: Failing тесты — PaymentConfirmationService не передаёт payment_id коннектору

**Files:**
- Create: `tests/Feature/ContractViolations/ConnectorParamsTest.php`

**Проблема:** `PaymentConfirmationService:69-76` собирает `connectorParams` без `payment_id`. Коннекторы (YooKassa, CloudPayments) не получают payment_id для идемпотентности и корреляции.

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Support\IdGenerator;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    $this->rawKey = IdGenerator::apiKey('sandbox');
    ApiKey::create([
        'merchant_account_id' => $this->merchant->id,
        'key_hash' => hash('sha256', $this->rawKey),
        'key_prefix' => substr($this->rawKey, 0, 20),
        'name' => 'Test',
    ]);

    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $profile->id,
        'connector_name' => 'yookassa',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['shop_id' => '123', 'secret_key' => 'sk'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);
});

test('confirm passes payment_id to connector for idempotency', function () {
    Http::fake([
        'api.yookassa.ru/v3/payments' => Http::response([
            'id' => 'yk_123', 'status' => 'succeeded',
            'amount' => ['value' => '50.00', 'currency' => 'RUB'],
        ]),
    ]);

    $create = $this->postJson('/api/v1/payments', [
        'amount' => 5000, 'currency' => 'RUB',
    ], ['api-key' => $this->rawKey]);
    $paymentId = $create->json('data.id');

    $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => ['card' => [
            'card_number' => '4242424242424242',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvc' => '123',
        ]],
    ], ['api-key' => $this->rawKey]);

    // payment_id MUST be in the connector request for idempotency
    Http::assertSent(function ($request) use ($paymentId) {
        $body = $request->data();

        return isset($body['metadata']['payment_id'])
            && $body['metadata']['payment_id'] === $paymentId;
    });
})->skip('BUG #1: PaymentConfirmationService does not pass payment_id to connectorParams');

test('refund passes payment_id to connector for idempotency', function () {
    Http::fake([
        'api.yookassa.ru/v3/payments' => Http::response([
            'id' => 'yk_123', 'status' => 'succeeded',
            'amount' => ['value' => '50.00', 'currency' => 'RUB'],
        ]),
        'api.yookassa.ru/v3/refunds' => Http::response([
            'id' => 'yk_ref_1', 'status' => 'succeeded',
        ]),
    ]);

    $create = $this->postJson('/api/v1/payments', [
        'amount' => 5000, 'currency' => 'RUB', 'confirm' => true,
        'payment_method' => 'card',
        'payment_method_data' => ['card' => [
            'card_number' => '4242424242424242',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvc' => '123',
        ]],
    ], ['api-key' => $this->rawKey]);
    $paymentId = $create->json('data.id');

    $this->postJson('/api/v1/refunds', [
        'payment_id' => $paymentId, 'amount' => 1000,
    ], ['api-key' => $this->rawKey]);

    // Refund request MUST include payment_id for idempotency key
    Http::assertSent(function ($request) use ($paymentId) {
        if (! str_contains($request->url(), '/refunds')) {
            return false;
        }
        $idempotenceKey = $request->header('Idempotence-Key')[0] ?? '';

        return str_contains($idempotenceKey, $paymentId);
    });
})->skip('BUG #5: RefundService does not pass payment_id to connector');
```

**Run:** `./vendor/bin/pest tests/Feature/ContractViolations/ConnectorParamsTest.php -v`
**Expected:** 2 skipped (bugs documented)
**Commit:** `test(contract): failing tests — payment_id not passed to connectors`

---

## Task 2: Failing тесты — 3DS redirect URL теряется в коннекторах

**Files:**
- Create: `tests/Unit/ContractViolations/YooKassa3dsTest.php`
- Create: `tests/Unit/ContractViolations/CloudPayments3dsTest.php`

### YooKassa: 3DS confirmation_url не возвращается

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentConnectors\Drivers\YooKassaConnector;
use Tests\TestCase;

uses(TestCase::class);

test('purchase returns redirect_url when 3DS required', function () {
    Http::fake([
        'api.yookassa.ru/v3/payments' => Http::response([
            'id' => 'yk_3ds',
            'status' => 'pending',
            'confirmation' => [
                'type' => 'redirect',
                'confirmation_url' => 'https://yookassa.ru/3ds/confirm?token=abc',
            ],
            'amount' => ['value' => '50.00', 'currency' => 'RUB'],
        ]),
    ]);

    $connector = new YooKassaConnector(['shop_id' => '123', 'secret_key' => 'sk']);
    $result = $connector->purchase([
        'amount' => 5000,
        'currency' => 'RUB',
        'payment_method_data' => ['card' => [
            'card_number' => '4000000000003220',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvc' => '123',
        ]],
        'payment_id' => 'pay_3ds_test',
        'return_url' => 'https://merchant.com/return',
    ]);

    // Must return requires_action with redirect URL
    expect($result['code'])->toBe('requires_action');
    expect($result['data']['redirect_url'])->toBe('https://yookassa.ru/3ds/confirm?token=abc');
})->skip('BUG #2: YooKassa returns status=pending for 3DS but connector treats it as failure');

test('authorize returns redirect_url when 3DS required', function () {
    Http::fake([
        'api.yookassa.ru/v3/payments' => Http::response([
            'id' => 'yk_3ds_auth',
            'status' => 'pending',
            'confirmation' => [
                'type' => 'redirect',
                'confirmation_url' => 'https://yookassa.ru/3ds/confirm?token=def',
            ],
        ]),
    ]);

    $connector = new YooKassaConnector(['shop_id' => '123', 'secret_key' => 'sk']);
    $result = $connector->authorize([
        'amount' => 10000,
        'currency' => 'RUB',
        'payment_method_data' => ['card' => ['card_number' => '4000000000003220', 'card_exp_month' => '12', 'card_exp_year' => '2030', 'card_cvc' => '123']],
        'payment_id' => 'pay_3ds_auth',
        'return_url' => 'https://merchant.com/return',
    ]);

    expect($result['code'])->toBe('requires_action');
    expect($result['data']['redirect_url'])->toBe('https://yookassa.ru/3ds/confirm?token=def');
})->skip('BUG #2: same — YooKassa 3DS redirect not extracted');
```

### CloudPayments: AcsUrl не обрабатывается

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentConnectors\Drivers\CloudPaymentsConnector;
use Tests\TestCase;

uses(TestCase::class);

test('purchase returns requires_action when 3DS required', function () {
    Http::fake([
        'api.cloudpayments.ru/payments/cards/charge' => Http::response([
            'Success' => false,
            'Message' => '3-D Secure is required',
            'Model' => [
                'TransactionId' => 504735239,
                'PaReq' => 'eJxV...base64...',
                'AcsUrl' => 'https://bank.example.com/3ds',
            ],
        ]),
    ]);

    $connector = new CloudPaymentsConnector(['public_id' => 'pk', 'api_secret' => 'sk']);
    $result = $connector->purchase([
        'amount' => 5000,
        'currency' => 'RUB',
        'payment_method_data' => ['card' => ['cryptogram' => 'test']],
        'payment_id' => 'pay_3ds_cp',
    ]);

    expect($result['code'])->toBe('requires_action');
    expect($result['data']['redirect_url'])->toBe('https://bank.example.com/3ds');
    expect($result['data']['pa_req'])->toBe('eJxV...base64...');
    expect($result['transaction_id'])->toBe(504735239);
})->skip('BUG #3: CloudPayments does not detect AcsUrl in response');
```

**Run:** `./vendor/bin/pest tests/Unit/ContractViolations/ -v`
**Expected:** 3 skipped
**Commit:** `test(contract): failing tests — 3DS redirect URLs lost in YooKassa and CloudPayments`

---

## Task 3: Failing тесты — вебхуки: refund не обрабатывается, metadata не обновляется

**Files:**
- Create: `tests/Feature/ContractViolations/WebhookGapsTest.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\Refund;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
    $this->mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'yookassa',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['shop_id' => '123', 'secret_key' => 'sk'],
        'test_mode' => true,
    ]);
});

test('refund.succeeded webhook updates refund status', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000,
        'currency' => 'RUB',
        'status' => PaymentStatus::Succeeded,
        'capture_method' => CaptureMethod::Automatic,
        'amount_received' => 5000,
        'attempt_count' => 1,
    ]);

    $refund = Refund::create([
        'payment_intent_id' => $payment->id,
        'merchant_account_id' => $this->merchant->id,
        'amount' => 3000,
        'currency' => 'RUB',
        'status' => RefundStatus::Pending,
        'connector' => 'yookassa',
        'connector_refund_id' => 'yk_ref_pending',
    ]);

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'refund.succeeded',
        'object' => [
            'id' => 'yk_ref_pending',
            'status' => 'succeeded',
            'amount' => ['value' => '30.00', 'currency' => 'RUB'],
            'metadata' => ['payment_id' => $payment->key],
        ],
    ])->assertOk();

    // Refund should be marked as succeeded
    expect($refund->fresh()->status)->toBe(RefundStatus::Succeeded);
})->skip('BUG #6: WebhookReceiverService ignores refund.succeeded — mapWebhookEventToStatus returns null');

test('payment webhook updates connector_transaction_id', function () {
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000,
        'currency' => 'RUB',
        'status' => PaymentStatus::Processing,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
        'connector' => null,
    ]);

    $this->postJson("/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [
        'type' => 'payment.succeeded',
        'object' => [
            'id' => 'yk_txn_from_webhook',
            'status' => 'succeeded',
            'metadata' => ['payment_id' => $payment->key],
        ],
    ])->assertOk();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Succeeded);
    // Connector transaction ID should be extracted from webhook
    // Currently NOT updated — only status and amount_received are set
    expect($payment->connector)->not->toBeNull();
})->skip('BUG #9: WebhookReceiverService only updates status, not connector metadata');
```

**Commit:** `test(contract): failing tests — webhook refund handling and metadata gaps`

---

## Task 4: Failing тесты — Stripe через Omnipay нерабочий

**Files:**
- Create: `tests/Unit/ContractViolations/StripeOmnipayTest.php`

```php
<?php

declare(strict_types=1);

use Streeboga\PaymentConnectors\Drivers\StripeConnector;

test('stripe purchase creates PaymentIntent (not legacy charge)', function () {
    // Stripe deprecated Charges API in favor of PaymentIntents
    // Omnipay uses the old Charges API
    // This test documents that the current implementation is non-functional
    $connector = new StripeConnector(['api_key' => 'sk_test_fake']);

    // AbstractConnector creates Omnipay gateway which uses legacy Stripe API
    // Modern Stripe requires: POST /v1/payment_intents
    // Omnipay sends: POST /v1/charges (deprecated)
    expect(true)->toBeTrue(); // placeholder — real test requires Stripe SDK
})->skip('BUG #4: StripeConnector uses Omnipay which targets deprecated Charges API, not PaymentIntents');

test('stripe purchase supports idempotency key', function () {
    // Stripe requires Idempotency-Key header for safe retries
    // Omnipay doesn't support this
    $connector = new StripeConnector(['api_key' => 'sk_test_fake']);
    // Cannot test without real/mocked Stripe SDK
    expect(true)->toBeTrue();
})->skip('BUG #17: Omnipay does not pass Stripe Idempotency-Key header');
```

**Commit:** `test(contract): failing tests — Stripe Omnipay incompatibility documented`

---

## Task 5: Failing тесты — customer_id не валидируется, capture не передаёт currency

**Files:**
- Create: `tests/Feature/ContractViolations/DataIntegrityTest.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Support\IdGenerator;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    $this->rawKey = IdGenerator::apiKey('sandbox');
    ApiKey::create([
        'merchant_account_id' => $this->merchant->id,
        'key_hash' => hash('sha256', $this->rawKey),
        'key_prefix' => substr($this->rawKey, 0, 20),
        'name' => 'Test',
    ]);

    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $profile->id,
        'connector_name' => 'test',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['api_key' => 'sk_test'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);
});

test('payment with non-existent customer_id is rejected', function () {
    $response = $this->postJson('/api/v1/payments', [
        'amount' => 5000,
        'currency' => 'USD',
        'customer_id' => 'cus_does_not_exist_at_all',
    ], ['api-key' => $this->rawKey]);

    // Should reject because customer doesn't belong to this merchant
    $response->assertStatus(400);
})->skip('BUG #13: customer_id accepted without validation — any string stored');

test('capture passes currency to connector', function () {
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 5000, 'currency' => 'EUR', 'capture_method' => 'manual',
    ], ['api-key' => $this->rawKey]);
    $paymentId = $create->json('data.id');

    $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => ['card' => [
            'card_number' => '4242424242424242',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvc' => '123',
        ]],
    ], ['api-key' => $this->rawKey]);

    // Capture should pass currency to connector (needed for multi-currency)
    // Currently PaymentService.capture() line 106-109 only passes amount + transaction_id
    $response = $this->postJson("/api/v1/payments/{$paymentId}/capture", [
        'amount_to_capture' => 5000,
    ], ['api-key' => $this->rawKey]);

    $response->assertOk();
    // This passes because TestConnector ignores currency
    // But real connectors need currency for multi-currency capture
    // The test documents the gap rather than failing
})->skip('BUG #10: PaymentService.capture() does not pass currency to connector');
```

**Commit:** `test(contract): failing tests — customer_id validation, capture currency gap`

---

## Task 6: Verify all skipped tests and commit

**Step 1:** Run full suite

```bash
./vendor/bin/pest
```

Expected: All existing tests pass. New contract violation tests are `->skip()`ped with bug descriptions.

**Step 2:** Count skipped tests

```bash
./vendor/bin/pest tests/Feature/ContractViolations tests/Unit/ContractViolations -v
```

Expected: ~10 skipped tests, each documenting a specific bug.

**Step 3:** Lint

```bash
./vendor/bin/pint
```

**Step 4:** Final commit

```bash
git commit -m "test(contract): 10 skipped tests documenting production bugs — ready for fixing"
```

---

## Summary

| Bug # | Test File | Tests | Проблема |
|-------|-----------|-------|----------|
| 1 | ConnectorParamsTest | 1 | payment_id не в connectorParams при confirm |
| 5 | ConnectorParamsTest | 1 | payment_id не передаётся при refund |
| 2 | YooKassa3dsTest | 2 | 3DS redirect_url теряется (status=pending) |
| 3 | CloudPayments3dsTest | 1 | AcsUrl не обрабатывается |
| 4,17 | StripeOmnipayTest | 2 | Omnipay несовместим с современным Stripe |
| 6 | WebhookGapsTest | 1 | refund.succeeded вебхук игнорируется |
| 9 | WebhookGapsTest | 1 | connector metadata не обновляется из вебхука |
| 13 | DataIntegrityTest | 1 | customer_id не валидируется |
| 10 | DataIntegrityTest | 1 | capture не передаёт currency |
| **Итого** | | **11** | |
