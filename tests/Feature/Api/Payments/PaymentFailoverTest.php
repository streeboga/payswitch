<?php

declare(strict_types=1);

use App\Enums\RoutingRuleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Streeboga\PaymentConnectors\ConnectorCapabilities;
use Streeboga\PaymentConnectors\ConnectorFactory;
use Streeboga\PaymentData\Contracts\ConnectorInterface;
use Streeboga\PaymentData\Enums\AmountUnit;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\SessionResultType;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\RoutingRule;
use Streeboga\PaymentData\Support\IdGenerator;
use Tests\Helpers\ScriptedConnector;

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

        public static function capabilities(): ConnectorCapabilities
        {
            return new ConnectorCapabilities(
                defaultDisplayName: ['en' => 'Test'],
                logoPath: '/logos/test.svg',
                directMethods: [],
                fallbackSessionType: SessionResultType::ServerRedirect,
                amountUnit: AmountUnit::MinorUnits,
            );
        }

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

        public function testConnection(): array
        {
            return ['success' => true, 'message' => 'ok'];
        }

        public function mapPaymentStatusToInternal(string $rawStatus): ?PaymentStatus
        {
            return null;
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

/**
 * Коннектор, явно отклоняющий карту (ответ провайдера с кодом отказа).
 */
function failoverDecliningConnector(): void
{
    ScriptedConnector::register('declining', [
        'purchase' => ['success' => false, 'transaction_id' => null, 'message' => 'Your card was declined', 'code' => 'card_declined'],
    ]);

    MerchantConnectorAccount::create([
        'merchant_account_id' => test()->merchant->id,
        'business_profile_id' => test()->profile->id,
        'connector_name' => 'declining',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['api_key' => 'sk_decline'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);
}

test('priority routing: first connector declines, second succeeds', function () {
    failoverDecliningConnector();
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'type' => RoutingRuleType::Priority,
        'name' => 'Primary with fallback',
        'rules' => ['connectors' => ['declining', 'test']],
        'active' => true,
        'priority' => 1,
    ]);

    $paymentId = failoverCreatePayment()->json('data.id');

    failoverConfirmPayment($paymentId)
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'succeeded');

    $this->assertDatabaseHas('payment_attempts', ['connector' => 'declining', 'status' => 'failed', 'error_code' => 'card_declined']);
    $this->assertDatabaseHas('payment_attempts', ['connector' => 'test', 'status' => 'succeeded']);
});

test('rule-based routing: matched connector declines, fallback succeeds', function () {
    failoverDecliningConnector();
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'type' => RoutingRuleType::RuleBased,
        'name' => 'USD to declining',
        'rules' => [
            'conditions' => [
                ['field' => 'currency', 'operator' => '==', 'value' => 'USD', 'connector' => 'declining'],
            ],
        ],
        'active' => true,
        'priority' => 1,
    ]);

    $paymentId = failoverCreatePayment(['currency' => 'USD'])->json('data.id');

    failoverConfirmPayment($paymentId)
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'succeeded');

    $this->assertDatabaseHas('payment_attempts', ['connector' => 'declining', 'status' => 'failed']);
    $this->assertDatabaseHas('payment_attempts', ['connector' => 'test', 'status' => 'succeeded']);
});

test('all connectors decline results in failed payment', function () {
    MerchantConnectorAccount::where('merchant_account_id', $this->merchant->id)->whereIn('connector_name', ['test', 'throwing'])->delete();
    failoverDecliningConnector();

    $paymentId = failoverCreatePayment()->json('data.id');

    failoverConfirmPayment($paymentId)
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'failed');

    $this->assertDatabaseMissing('payment_attempts', ['status' => 'succeeded']);
});

// П6: исключение, таймаут или неразобранный ответ — первый провайдер мог списать.
// Данные карты второму не уходят: платёж остаётся processing, клиенту 502.

test('connector exception: no fallback, payment stays processing, 502', function () {
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'type' => RoutingRuleType::Priority,
        'name' => 'Primary with fallback',
        'rules' => ['connectors' => ['throwing', 'test']],
        'active' => true,
        'priority' => 1,
    ]);

    $paymentId = failoverCreatePayment()->json('data.id');

    failoverConfirmPayment($paymentId)
        ->assertStatus(502)
        ->assertJsonPath('errors.0.code', 'connector_outcome_unknown');

    $this->getJson("/api/v1/payments/{$paymentId}", failoverHeaders())
        ->assertJsonPath('data.attributes.status', 'processing')
        ->assertJsonPath('data.attributes.connector', 'throwing');

    $this->assertDatabaseHas('payment_attempts', ['connector' => 'throwing', 'status' => 'failed', 'error_code' => 'connector_exception']);
    $this->assertDatabaseMissing('payment_attempts', ['connector' => 'test']);
});

test('timeout swallowed by driver or unparsed answer: no fallback', function (array $answer) {
    ScriptedConnector::register('flaky', ['purchase' => $answer]);
    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'flaky',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['api_key' => 'sk_flaky'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);
    RoutingRule::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'type' => RoutingRuleType::Priority,
        'name' => 'Flaky first',
        'rules' => ['connectors' => ['flaky', 'test']],
        'active' => true,
        'priority' => 1,
    ]);

    $paymentId = failoverCreatePayment()->json('data.id');

    failoverConfirmPayment($paymentId)->assertStatus(502);

    $this->assertDatabaseMissing('payment_attempts', ['connector' => 'test']);
    expect(PaymentIntent::where('key', $paymentId)->sole()->status->value)->toBe('processing');
})->with([
    'connector_error' => [['success' => false, 'transaction_id' => null, 'message' => 'cURL error 28', 'code' => 'connector_error']],
    'пустой код' => [['success' => false, 'transaction_id' => null, 'message' => 'T-Bank error', 'code' => '']],
]);
