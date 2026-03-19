# Phase 2: Routing Edge Cases, Failover & Tenant Isolation

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Закрыть пробелы в тестировании роутинга (failover, edge cases, malformed rules) и добавить строгие тесты изоляции тенантов на уровне API.

**Architecture:** Чистые тесты — проверяем существующий код, чиним где не соответствует контракту. Два файла: routing edge cases (unit-level через RoutingService) и tenant isolation (feature-level через HTTP API).

**Tech Stack:** Pest PHP, RefreshDatabase, RoutingService, RoutingRule model, API key auth

---

## Task 1: Routing edge cases — volume split и malformed rules

**Files:**
- Create: `tests/Feature/Services/RoutingEdgeCasesTest.php`

**Step 1: Write the test file**

```php
<?php

declare(strict_types=1);

use App\Services\RoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\RoutingRule;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    foreach (['stripe', 'cloudpayments', 'test'] as $name) {
        MerchantConnectorAccount::create([
            'merchant_account_id' => $this->merchant->id,
            'business_profile_id' => $profile->id,
            'connector_name' => $name,
            'connector_type' => 'fiz_operations',
            'connector_account_details' => ['api_key' => 'test'],
            'payment_methods_enabled' => [['payment_method' => 'card']],
            'test_mode' => true,
        ]);
    }

    $this->routing = app(RoutingService::class);
});

// --- Volume split edge cases ---

test('volume split with all zero weights falls through to next resolution', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'volume_split',
        'name' => 'Zero weights',
        'rules' => ['split' => [
            ['connector' => 'stripe', 'weight' => 0],
            ['connector' => 'cloudpayments', 'weight' => 0],
        ]],
        'active' => true,
    ]);

    // Should fall through volume split (all zero) → auto-select → first active
    $mca = $this->routing->resolve($this->merchant->id, null, 'card');
    expect($mca)->not->toBeNull();
});

test('volume split skips disabled connector and picks another', function () {
    MerchantConnectorAccount::where('connector_name', 'stripe')->update(['disabled' => true]);

    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'volume_split',
        'name' => 'Split with disabled',
        'rules' => ['split' => [
            ['connector' => 'stripe', 'weight' => 90],
            ['connector' => 'cloudpayments', 'weight' => 10],
        ]],
        'active' => true,
    ]);

    // Even if stripe is selected by weight, it's disabled — should eventually resolve
    $results = collect(range(1, 50))->map(fn () => $this->routing->resolve($this->merchant->id)->connector_name);

    // Stripe is disabled, so cloudpayments should get all traffic
    // Or fall through to auto-select
    expect($results->contains('stripe'))->toBeFalse();
});

test('volume split with empty split array falls through', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'volume_split',
        'name' => 'Empty split',
        'rules' => ['split' => []],
        'active' => true,
    ]);

    $mca = $this->routing->resolve($this->merchant->id, null, 'card');
    expect($mca)->not->toBeNull();
});

// --- Priority edge cases ---

test('priority list with all disabled connectors falls through', function () {
    MerchantConnectorAccount::where('connector_name', 'stripe')->update(['disabled' => true]);
    MerchantConnectorAccount::where('connector_name', 'cloudpayments')->update(['disabled' => true]);

    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'priority',
        'name' => 'All disabled in list',
        'rules' => ['connectors' => ['stripe', 'cloudpayments']],
        'active' => true,
    ]);

    // Priority list fails → falls through to auto-select → test connector
    $mca = $this->routing->resolve($this->merchant->id, null, 'card');
    expect($mca->connector_name)->toBe('test');
});

test('priority list with empty connectors array falls through', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'priority',
        'name' => 'Empty list',
        'rules' => ['connectors' => []],
        'active' => true,
    ]);

    $mca = $this->routing->resolve($this->merchant->id, null, 'card');
    expect($mca)->not->toBeNull();
});

// --- Rule-based edge cases ---

test('rule-based with malformed condition is skipped', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'rule_based',
        'name' => 'Malformed rule',
        'rules' => [
            'conditions' => [
                ['field' => 'currency'], // missing operator, value, connector
                ['field' => 'currency', 'operator' => '==', 'value' => 'USD', 'connector' => 'stripe'],
            ],
            'default_connector' => 'test',
        ],
        'active' => true,
    ]);

    // Malformed condition skipped, second condition matches
    $mca = $this->routing->resolve($this->merchant->id, null, null, 'USD');
    expect($mca->connector_name)->toBe('stripe');
});

test('rule-based with unknown operator returns false match', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'rule_based',
        'name' => 'Bad operator',
        'rules' => [
            'conditions' => [
                ['field' => 'currency', 'operator' => '~=', 'value' => 'USD', 'connector' => 'stripe'],
            ],
            'default_connector' => 'cloudpayments',
        ],
        'active' => true,
    ]);

    $mca = $this->routing->resolve($this->merchant->id, null, null, 'USD');
    expect($mca->connector_name)->toBe('cloudpayments'); // falls to default
});

test('rule-based with unknown field skips condition', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'rule_based',
        'name' => 'Unknown field',
        'rules' => [
            'conditions' => [
                ['field' => 'country', 'operator' => '==', 'value' => 'RU', 'connector' => 'stripe'],
            ],
            'default_connector' => 'test',
        ],
        'active' => true,
    ]);

    $mca = $this->routing->resolve($this->merchant->id, null, null, 'USD');
    expect($mca->connector_name)->toBe('test');
});

test('rule-based in operator matches value in array', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'rule_based',
        'name' => 'In operator',
        'rules' => [
            'conditions' => [
                ['field' => 'currency', 'operator' => 'in', 'value' => ['RUB', 'KZT', 'BYN'], 'connector' => 'cloudpayments'],
            ],
            'default_connector' => 'stripe',
        ],
        'active' => true,
    ]);

    expect($this->routing->resolve($this->merchant->id, null, null, 'RUB')->connector_name)->toBe('cloudpayments');
    expect($this->routing->resolve($this->merchant->id, null, null, 'KZT')->connector_name)->toBe('cloudpayments');
    expect($this->routing->resolve($this->merchant->id, null, null, 'USD')->connector_name)->toBe('stripe');
});

// --- Rule priority ordering ---

test('higher priority rule evaluated first', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'rule_based',
        'name' => 'Low priority',
        'rules' => ['conditions' => [['field' => 'currency', 'operator' => '==', 'value' => 'USD', 'connector' => 'cloudpayments']]],
        'active' => true,
        'priority' => 1,
    ]);

    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'rule_based',
        'name' => 'High priority',
        'rules' => ['conditions' => [['field' => 'currency', 'operator' => '==', 'value' => 'USD', 'connector' => 'stripe']]],
        'active' => true,
        'priority' => 10,
    ]);

    $mca = $this->routing->resolve($this->merchant->id, null, null, 'USD');
    expect($mca->connector_name)->toBe('stripe'); // priority 10 wins
});

// --- Routing rules are merchant-scoped ---

test('routing rules from another merchant are not evaluated', function () {
    $org2 = Organization::create(['name' => 'Org2']);
    $merchant2 = MerchantAccount::create(['org_id' => $org2->id, 'name' => 'M2']);
    $profile2 = BusinessProfile::create(['merchant_account_id' => $merchant2->id]);
    MerchantConnectorAccount::create([
        'merchant_account_id' => $merchant2->id,
        'business_profile_id' => $profile2->id,
        'connector_name' => 'yookassa',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['api_key' => 'test'],
        'test_mode' => true,
    ]);

    // Rule belongs to merchant2
    RoutingRule::create([
        'merchant_account_id' => $merchant2->id,
        'type' => 'rule_based',
        'name' => 'Other merchant rule',
        'rules' => ['conditions' => [['field' => 'currency', 'operator' => '==', 'value' => 'USD', 'connector' => 'yookassa']]],
        'active' => true,
        'priority' => 100,
    ]);

    // Merchant1 should NOT use merchant2's rules
    $mca = $this->routing->resolve($this->merchant->id, null, null, 'USD');
    expect($mca->connector_name)->not->toBe('yookassa');
});
```

**Step 2: Run tests**

Run: `./vendor/bin/pest tests/Feature/Services/RoutingEdgeCasesTest.php -v`

**Step 3: Fix any failures in production code if routing doesn't handle edge cases correctly**

**Step 4: Commit**

```bash
git add tests/Feature/Services/RoutingEdgeCasesTest.php
git commit -m "test: routing edge cases — volume split, malformed rules, priority ordering, merchant scoping"
```

---

## Task 2: Tenant isolation — cross-merchant API access prevention

**Files:**
- Create: `tests/Feature/Api/TenantIsolationTest.php`

**Step 1: Write the test file**

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
    // --- Merchant A ---
    $orgA = Organization::create(['name' => 'Org A']);
    $this->merchantA = MerchantAccount::create(['org_id' => $orgA->id, 'name' => 'Merchant A']);
    $profileA = BusinessProfile::create(['merchant_account_id' => $this->merchantA->id]);

    $this->keyA = IdGenerator::apiKey('sandbox');
    ApiKey::create([
        'merchant_account_id' => $this->merchantA->id,
        'key_hash' => hash('sha256', $this->keyA),
        'key_prefix' => substr($this->keyA, 0, 20),
        'name' => 'Key A',
    ]);

    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchantA->id,
        'business_profile_id' => $profileA->id,
        'connector_name' => 'test',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    // --- Merchant B ---
    $orgB = Organization::create(['name' => 'Org B']);
    $this->merchantB = MerchantAccount::create(['org_id' => $orgB->id, 'name' => 'Merchant B']);
    $profileB = BusinessProfile::create(['merchant_account_id' => $this->merchantB->id]);

    $this->keyB = IdGenerator::apiKey('sandbox');
    ApiKey::create([
        'merchant_account_id' => $this->merchantB->id,
        'key_hash' => hash('sha256', $this->keyB),
        'key_prefix' => substr($this->keyB, 0, 20),
        'name' => 'Key B',
    ]);

    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchantB->id,
        'business_profile_id' => $profileB->id,
        'connector_name' => 'test',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test_b'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);
});

// --- Payments ---

test('merchant B cannot read merchant A payment', function () {
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 5000, 'currency' => 'USD',
    ], ['api-key' => $this->keyA]);
    $paymentId = $create->json('data.id');

    $this->getJson("/api/v1/payments/{$paymentId}", ['api-key' => $this->keyB])
        ->assertStatus(404);
});

test('merchant B cannot confirm merchant A payment', function () {
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 5000, 'currency' => 'USD',
    ], ['api-key' => $this->keyA]);
    $paymentId = $create->json('data.id');

    $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => ['card' => [
            'card_number' => '4242424242424242',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvc' => '123',
        ]],
    ], ['api-key' => $this->keyB])
        ->assertStatus(404);
});

test('merchant B cannot capture merchant A payment', function () {
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 5000, 'currency' => 'USD', 'capture_method' => 'manual',
    ], ['api-key' => $this->keyA]);
    $paymentId = $create->json('data.id');

    $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => ['card' => [
            'card_number' => '4242424242424242',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvc' => '123',
        ]],
    ], ['api-key' => $this->keyA]);

    $this->postJson("/api/v1/payments/{$paymentId}/capture", [
        'amount_to_capture' => 5000,
    ], ['api-key' => $this->keyB])
        ->assertStatus(404);
});

test('merchant B cannot cancel merchant A payment', function () {
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 5000, 'currency' => 'USD',
    ], ['api-key' => $this->keyA]);
    $paymentId = $create->json('data.id');

    $this->postJson("/api/v1/payments/{$paymentId}/cancel", [], ['api-key' => $this->keyB])
        ->assertStatus(404);
});

// --- Refunds ---

test('merchant B cannot refund merchant A payment', function () {
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 5000, 'currency' => 'USD', 'confirm' => true,
        'payment_method' => 'card',
        'payment_method_data' => ['card' => [
            'card_number' => '4242424242424242',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvc' => '123',
        ]],
    ], ['api-key' => $this->keyA]);
    $paymentId = $create->json('data.id');

    $this->postJson('/api/v1/refunds', [
        'payment_id' => $paymentId, 'amount' => 1000,
    ], ['api-key' => $this->keyB])
        ->assertStatus(404);
});

test('merchant B cannot read merchant A refund', function () {
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 5000, 'currency' => 'USD', 'confirm' => true,
        'payment_method' => 'card',
        'payment_method_data' => ['card' => [
            'card_number' => '4242424242424242',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvc' => '123',
        ]],
    ], ['api-key' => $this->keyA]);
    $paymentId = $create->json('data.id');

    $refund = $this->postJson('/api/v1/refunds', [
        'payment_id' => $paymentId, 'amount' => 1000,
    ], ['api-key' => $this->keyA]);
    $refundId = $refund->json('data.id');

    $this->getJson("/api/v1/refunds/{$refundId}", ['api-key' => $this->keyB])
        ->assertStatus(404);
});

// --- Payment listing ---

test('payment list is scoped to own merchant', function () {
    // Merchant A creates 2 payments
    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], ['api-key' => $this->keyA]);
    $this->postJson('/api/v1/payments', ['amount' => 2000, 'currency' => 'USD'], ['api-key' => $this->keyA]);

    // Merchant B creates 1 payment
    $this->postJson('/api/v1/payments', ['amount' => 3000, 'currency' => 'EUR'], ['api-key' => $this->keyB]);

    // Merchant A sees only their payments
    $listA = $this->getJson('/api/v1/payments', ['api-key' => $this->keyA]);
    $listA->assertOk();
    expect(count($listA->json('data')))->toBe(2);

    // Merchant B sees only their payment
    $listB = $this->getJson('/api/v1/payments', ['api-key' => $this->keyB]);
    $listB->assertOk();
    expect(count($listB->json('data')))->toBe(1);
});

// --- API key scope ---

test('revoked API key cannot access payments', function () {
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 5000, 'currency' => 'USD',
    ], ['api-key' => $this->keyA]);
    $create->assertStatus(201);

    // Revoke the key
    ApiKey::where('key_hash', hash('sha256', $this->keyA))->first()->revoke();

    $this->getJson('/api/v1/payments', ['api-key' => $this->keyA])
        ->assertStatus(401);
});

test('invalid API key is rejected', function () {
    $this->getJson('/api/v1/payments', ['api-key' => 'snd_completely_fake_key'])
        ->assertStatus(401);
});

test('missing API key is rejected', function () {
    $this->getJson('/api/v1/payments')
        ->assertStatus(401);
});
```

**Step 2: Run tests**

Run: `./vendor/bin/pest tests/Feature/Api/TenantIsolationTest.php -v`

**Step 3: Fix any failures — especially check 401 vs 403 vs 404 responses**

**Step 4: Commit**

```bash
git add tests/Feature/Api/TenantIsolationTest.php
git commit -m "test: tenant isolation — cross-merchant access prevention for payments, refunds, API keys"
```

---

## Task 3: Failover integration test — routing rule connector fails, fallback works

**Files:**
- Create: `tests/Feature/Api/Payments/PaymentFailoverTest.php`

**Step 1: Write the test file**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentConnectors\ConnectorFactory;
use Streeboga\PaymentData\Contracts\ConnectorInterface;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\RoutingRule;
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

    // Throwing connector
    $throwingClass = new class([]) implements ConnectorInterface {
        public function __construct(?array $c = []) {}
        public function getName(): string { return 'throwing'; }
        public function authorize(array $p): array { throw new \RuntimeException('Connection timeout'); }
        public function purchase(array $p): array { throw new \RuntimeException('Connection timeout'); }
        public function capture(array $p): array { return ['success' => true, 'transaction_id' => 'x']; }
        public function refund(array $p): array { return ['success' => true, 'transaction_id' => 'x']; }
        public function verifyWebhookSignature(string $p, array $h): bool { return false; }
        public function mapWebhookEventToStatus(string $e): ?PaymentStatus { return null; }
        public function extractPaymentIdFromWebhook(array $p): ?string { return null; }
    };
    ConnectorFactory::register('throwing', get_class($throwingClass));

    // Primary connector (will throw)
    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $profile->id,
        'connector_name' => 'throwing',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['api_key' => 'sk_throw'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    // Fallback connector (will succeed)
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

test('priority routing: first connector fails, second succeeds', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'priority',
        'name' => 'Failover test',
        'rules' => ['connectors' => ['throwing', 'test']],
        'active' => true,
    ]);

    $create = $this->postJson('/api/v1/payments', [
        'amount' => 5000, 'currency' => 'USD',
    ], ['api-key' => $this->rawKey]);
    $paymentId = $create->json('data.id');

    $response = $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => ['card' => [
            'card_number' => '4242424242424242',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvc' => '123',
        ]],
    ], ['api-key' => $this->rawKey]);

    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'succeeded');

    // Failed attempt recorded for throwing connector
    $this->assertDatabaseHas('payment_attempts', [
        'connector' => 'throwing',
        'status' => 'failed',
        'error_code' => 'connector_exception',
    ]);

    // Successful attempt recorded for test connector
    $this->assertDatabaseHas('payment_attempts', [
        'connector' => 'test',
        'status' => 'succeeded',
    ]);
});

test('rule-based routing: matched connector fails, fallback succeeds', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'type' => 'rule_based',
        'name' => 'Currency failover',
        'rules' => [
            'conditions' => [
                ['field' => 'currency', 'operator' => '==', 'value' => 'USD', 'connector' => 'throwing'],
            ],
        ],
        'active' => true,
    ]);

    $create = $this->postJson('/api/v1/payments', [
        'amount' => 5000, 'currency' => 'USD',
    ], ['api-key' => $this->rawKey]);
    $paymentId = $create->json('data.id');

    $response = $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => ['card' => [
            'card_number' => '4242424242424242',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvc' => '123',
        ]],
    ], ['api-key' => $this->rawKey]);

    // Throwing fails → fallback to test → succeeds
    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'succeeded');
});

test('all connectors fail results in failed payment', function () {
    // Remove test connector, leaving only throwing
    MerchantConnectorAccount::where('connector_name', 'test')->delete();

    $create = $this->postJson('/api/v1/payments', [
        'amount' => 5000, 'currency' => 'USD',
    ], ['api-key' => $this->rawKey]);
    $paymentId = $create->json('data.id');

    $response = $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => ['card' => [
            'card_number' => '4242424242424242',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvc' => '123',
        ]],
    ], ['api-key' => $this->rawKey]);

    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'failed');
});
```

**Step 2: Run tests**

Run: `./vendor/bin/pest tests/Feature/Api/Payments/PaymentFailoverTest.php -v`

**Step 3: Fix any production code issues**

**Step 4: Commit**

```bash
git add tests/Feature/Api/Payments/PaymentFailoverTest.php
git commit -m "test: payment failover — priority routing, rule-based fallback, all-fail scenario"
```

---

## Task 4: Run full suite, verify

**Step 1: Run full suite**

Run: `./vendor/bin/pest`

**Step 2: Grep for weak assertions**

Run: `grep -r 'toBeIn' tests/ --include='*.php'`

**Step 3: Lint**

Run: `./vendor/bin/pint`

**Step 4: Commit if fixes needed**

---

## Summary

| Task | File | Tests |
|------|------|-------|
| 1 | `RoutingEdgeCasesTest.php` | ~12 |
| 2 | `TenantIsolationTest.php` | ~11 |
| 3 | `PaymentFailoverTest.php` | ~3 |
| 4 | Full suite verification | — |
| **Total** | | **~26 new tests** |
