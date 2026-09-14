<?php

declare(strict_types=1);

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

    // Test connector
    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'test',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test_xxx'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);
});

function apiHeaders(): array
{
    return ['api-key' => test()->rawKey];
}

function createPayment(array $attrs = []): TestResponse
{
    return test()->postJson('/api/v1/payments', array_merge([
        'amount' => 6540,
        'currency' => 'USD',
    ], $attrs), apiHeaders());
}

// --- GET payment ---

test('can retrieve payment by id', function () {
    $create = createPayment();
    $paymentId = $create->json('data.id');

    $response = $this->getJson("/api/v1/payments/{$paymentId}", apiHeaders());

    $response->assertOk()
        ->assertJsonPath('data.id', $paymentId)
        ->assertJsonPath('data.type', 'payments')
        ->assertJsonPath('data.attributes.status', 'requires_payment_method');
});

test('returns 404 for non-existent payment', function () {
    $this->getJson('/api/v1/payments/pay_nonexistent123456789012', apiHeaders())
        ->assertStatus(404)
        ->assertJsonStructure(['errors' => [['status', 'code']]]);
});

test('cannot access another merchants payment', function () {
    $create = createPayment();
    $paymentId = $create->json('data.id');

    // Create different merchant + key
    $org2 = Organization::create(['name' => 'Org2']);
    $merchant2 = MerchantAccount::create(['org_id' => $org2->id, 'name' => 'M2']);
    $rawKey2 = IdGenerator::apiKey('sandbox');
    ApiKey::create([
        'merchant_account_id' => $merchant2->id,
        'key_hash' => hash('sha256', $rawKey2),
        'key_prefix' => substr($rawKey2, 0, 20),
        'name' => 'Other',
    ]);

    $this->getJson("/api/v1/payments/{$paymentId}", ['api-key' => $rawKey2])
        ->assertStatus(404); // Should not find — scoped to merchant
});

// --- Confirm payment ---

test('can confirm payment with card data (automatic capture)', function () {
    $create = createPayment();
    $paymentId = $create->json('data.id');

    $response = $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => [
            'card' => [
                'card_number' => '4242424242424242',
                'card_exp_month' => '12',
                'card_exp_year' => '2030',
                'card_cvc' => '123',
            ],
        ],
    ], apiHeaders());

    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'succeeded')
        ->assertJsonPath('data.attributes.amount_received', 6540);
});

test('can confirm payment with manual capture', function () {
    $create = createPayment(['capture_method' => 'manual']);
    $paymentId = $create->json('data.id');

    $response = $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => [
            'card' => [
                'card_number' => '4242424242424242',
                'card_exp_month' => '12',
                'card_exp_year' => '2030',
                'card_cvc' => '123',
            ],
        ],
    ], apiHeaders());

    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'requires_capture')
        ->assertJsonPath('data.attributes.amount_capturable', 6540);
});

test('cannot confirm already succeeded payment', function () {
    $create = createPayment();
    $paymentId = $create->json('data.id');

    // First confirm
    $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => ['card' => ['card_number' => '4242424242424242', 'card_exp_month' => '12', 'card_exp_year' => '2030', 'card_cvc' => '123']],
    ], apiHeaders());

    // Second confirm should fail
    $response = $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => ['card' => ['card_number' => '4242424242424242', 'card_exp_month' => '12', 'card_exp_year' => '2030', 'card_cvc' => '123']],
    ], apiHeaders());

    $response->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'invalid_state_transition');
});

test('confirm with confirm:true on create works in one call', function () {
    $response = $this->postJson('/api/v1/payments', [
        'amount' => 5000,
        'currency' => 'USD',
        'confirm' => true,
        'payment_method' => 'card',
        'payment_method_data' => [
            'card' => [
                'card_number' => '4242424242424242',
                'card_exp_month' => '12',
                'card_exp_year' => '2030',
                'card_cvc' => '123',
            ],
        ],
    ], apiHeaders());

    $response->assertStatus(201)
        ->assertJsonPath('data.attributes.status', 'succeeded');
});

// --- Capture ---

test('can capture authorized payment', function () {
    $create = createPayment(['capture_method' => 'manual']);
    $paymentId = $create->json('data.id');

    // Confirm (manual → requires_capture)
    $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => ['card' => ['card_number' => '4242424242424242', 'card_exp_month' => '12', 'card_exp_year' => '2030', 'card_cvc' => '123']],
    ], apiHeaders());

    // Capture
    $response = $this->postJson("/api/v1/payments/{$paymentId}/capture", [
        'amount_to_capture' => 6540,
    ], apiHeaders());

    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'succeeded')
        ->assertJsonPath('data.attributes.amount_received', 6540);
});

test('cannot capture more than authorized amount', function () {
    $create = createPayment(['capture_method' => 'manual']);
    $paymentId = $create->json('data.id');

    $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => ['card' => ['card_number' => '4242424242424242', 'card_exp_month' => '12', 'card_exp_year' => '2030', 'card_cvc' => '123']],
    ], apiHeaders());

    $response = $this->postJson("/api/v1/payments/{$paymentId}/capture", [
        'amount_to_capture' => 99999,
    ], apiHeaders());

    $response->assertStatus(400);
});

test('cannot capture payment not in requires_capture status', function () {
    $create = createPayment(); // automatic capture
    $paymentId = $create->json('data.id');

    $response = $this->postJson("/api/v1/payments/{$paymentId}/capture", [
        'amount_to_capture' => 100,
    ], apiHeaders());

    $response->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'invalid_state_transition');
});

// --- Cancel ---

test('can cancel payment in non-terminal status', function () {
    $create = createPayment();
    $paymentId = $create->json('data.id');

    $response = $this->postJson("/api/v1/payments/{$paymentId}/cancel", [], apiHeaders());

    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'cancelled');
});

test('cannot cancel succeeded payment', function () {
    $create = createPayment();
    $paymentId = $create->json('data.id');

    // Confirm → succeeded
    $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => ['card' => ['card_number' => '4242424242424242', 'card_exp_month' => '12', 'card_exp_year' => '2030', 'card_cvc' => '123']],
    ], apiHeaders());

    $response = $this->postJson("/api/v1/payments/{$paymentId}/cancel", [], apiHeaders());

    $response->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'invalid_state_transition');
});

// --- Edge cases ---

test('connector exception during confirm does not fall back: payment stays processing', function () {
    // Register a throwing connector
    $throwingConnectorClass = new class([]) implements ConnectorInterface
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
    ConnectorFactory::register('throwing', get_class($throwingConnectorClass));

    // Create the throwing connector MCA with higher priority
    $profile = BusinessProfile::where('merchant_account_id', $this->merchant->id)->first();
    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $profile->id,
        'connector_name' => 'throwing',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_throw'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    $create = createPayment();
    $paymentId = $create->json('data.id');

    // Confirm with explicit throwing connector — primary fails, fallback (test) should work
    $response = $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'connector' => 'throwing',
        'payment_method_data' => ['card' => ['card_number' => '4242424242424242', 'card_exp_month' => '12', 'card_exp_year' => '2030', 'card_cvc' => '123']],
    ], apiHeaders());

    // Исключение — исход неизвестен, первый провайдер мог списать: карту второму не отдаём (П6).
    $response->assertStatus(502)->assertJsonPath('errors.0.code', 'connector_outcome_unknown');
    expect(PaymentIntent::where('key', $paymentId)->sole()->status->value)->toBe('processing');
    $this->assertDatabaseMissing('payment_attempts', ['connector' => 'test']);

    // Verify the failed attempt was recorded
    $this->assertDatabaseHas('payment_attempts', [
        'connector' => 'throwing',
        'status' => 'failed',
        'error_code' => 'connector_exception',
    ]);
});

test('confirm with expired payment returns error', function () {
    $create = createPayment(['session_expiry' => 60]);
    $paymentId = $create->json('data.id');

    // Manually expire the payment
    PaymentIntent::where('key', $paymentId)
        ->update(['expires_on' => now()->subMinutes(5)]);

    $response = $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => ['card' => ['card_number' => '4242424242424242', 'card_exp_month' => '12', 'card_exp_year' => '2030', 'card_cvc' => '123']],
    ], apiHeaders());

    $response->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'payment_expired');
});

// --- Audit log ---

test('payment status changes are logged in audit log', function () {
    $create = createPayment();
    $paymentId = $create->json('data.id');

    $this->postJson("/api/v1/payments/{$paymentId}/cancel", [], apiHeaders());

    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'payment',
        'event' => 'status_changed',
    ]);
});
