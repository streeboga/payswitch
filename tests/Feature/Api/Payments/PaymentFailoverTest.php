<?php

declare(strict_types=1);

use App\Enums\RoutingRuleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
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
    $this->profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    $this->rawKey = IdGenerator::apiKey('sandbox');
    ApiKey::create([
        'merchant_account_id' => $this->merchant->id,
        'key_hash' => hash('sha256', $this->rawKey),
        'key_prefix' => substr($this->rawKey, 0, 20),
        'name' => 'Test',
    ]);

    // Test connector (fallback)
    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'test',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test_xxx'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    // Register throwing connector
    $throwingClass = new class([]) implements ConnectorInterface
    {
        public function __construct(?array $credentials = []) {}

        public function getName(): string
        {
            return 'throwing';
        }

        public function authorize(array $params): array
        {
            throw new RuntimeException('Connection timeout');
        }

        public function purchase(array $params): array
        {
            throw new RuntimeException('Connection timeout');
        }

        public function capture(array $params): array
        {
            return ['success' => true, 'transaction_id' => 'x'];
        }

        public function refund(array $params): array
        {
            return ['success' => true, 'transaction_id' => 'x'];
        }

        public function void(array $params): array
        {
            return ['success' => true, 'transaction_id' => 'x'];
        }

        public function verifyWebhookSignature(string $payload, array $headers): bool
        {
            return false;
        }

        public function mapWebhookEventToStatus(string $eventType): ?PaymentStatus
        {
            return null;
        }

        public function extractPaymentIdFromWebhook(array $payload): ?string
        {
            return null;
        }

        public function getPaymentStatus(array $params): array
        {
            return ['success' => true, 'transaction_id' => 'x', 'code' => 'ok', 'data' => ['status' => 'succeeded']];
        }

        public function createPaymentSession(array $params): array
        {
            return ['success' => false, 'code' => 'not_supported'];
        }
    };
    ConnectorFactory::register('throwing', get_class($throwingClass));

    // Throwing connector MCA
    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'throwing',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_throw'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);
});

function failoverHeaders(): array
{
    return ['api-key' => test()->rawKey];
}

function failoverCreatePayment(array $attrs = []): TestResponse
{
    return test()->postJson('/api/v1/payments', array_merge([
        'amount' => 6540,
        'currency' => 'USD',
    ], $attrs), failoverHeaders());
}

function failoverConfirmPayment(string $paymentId): TestResponse
{
    return test()->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => [
            'card' => [
                'card_number' => '4242424242424242',
                'card_exp_month' => '12',
                'card_exp_year' => '2030',
                'card_cvc' => '123',
            ],
        ],
    ], failoverHeaders());
}

// --- Failover tests ---

test('priority routing: first connector fails, second succeeds', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'type' => RoutingRuleType::Priority,
        'name' => 'Primary with fallback',
        'rules' => ['connectors' => ['throwing', 'test']],
        'active' => true,
        'priority' => 1,
    ]);

    $create = failoverCreatePayment();
    $paymentId = $create->json('data.id');

    $response = failoverConfirmPayment($paymentId);

    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'succeeded');

    // Primary connector failed
    $this->assertDatabaseHas('payment_attempts', [
        'connector' => 'throwing',
        'status' => 'failed',
        'error_code' => 'connector_exception',
    ]);

    // Fallback connector succeeded
    $this->assertDatabaseHas('payment_attempts', [
        'connector' => 'test',
        'status' => 'succeeded',
    ]);
});

test('rule-based routing: matched connector fails, fallback succeeds', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'type' => RoutingRuleType::RuleBased,
        'name' => 'USD to throwing',
        'rules' => [
            'conditions' => [
                [
                    'field' => 'currency',
                    'operator' => '==',
                    'value' => 'USD',
                    'connector' => 'throwing',
                ],
            ],
        ],
        'active' => true,
        'priority' => 1,
    ]);

    $create = failoverCreatePayment(['currency' => 'USD']);
    $paymentId = $create->json('data.id');

    $response = failoverConfirmPayment($paymentId);

    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'succeeded');

    // Rule-matched connector failed
    $this->assertDatabaseHas('payment_attempts', [
        'connector' => 'throwing',
        'status' => 'failed',
        'error_code' => 'connector_exception',
    ]);

    // Fallback connector succeeded
    $this->assertDatabaseHas('payment_attempts', [
        'connector' => 'test',
        'status' => 'succeeded',
    ]);
});

test('all connectors fail results in failed payment', function () {
    // Remove the test connector so only throwing remains
    MerchantConnectorAccount::where('connector_name', 'test')
        ->where('merchant_account_id', $this->merchant->id)
        ->delete();

    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'type' => RoutingRuleType::Priority,
        'name' => 'Only throwing',
        'rules' => ['connectors' => ['throwing']],
        'active' => true,
        'priority' => 1,
    ]);

    $create = failoverCreatePayment();
    $paymentId = $create->json('data.id');

    $response = failoverConfirmPayment($paymentId);

    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'failed');

    $this->assertDatabaseHas('payment_attempts', [
        'connector' => 'throwing',
        'status' => 'failed',
        'error_code' => 'connector_exception',
    ]);

    // No successful attempt should exist
    $this->assertDatabaseMissing('payment_attempts', [
        'status' => 'succeeded',
    ]);
});
