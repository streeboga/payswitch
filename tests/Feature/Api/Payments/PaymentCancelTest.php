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

    // Spy connector that tracks void/refund calls
    $spyClass = new class([]) implements ConnectorInterface
    {
        public static bool $voidCalled = false;

        public static bool $refundCalled = false;

        public static ?array $voidParams = null;

        public function __construct(?array $credentials = [])
        {
            // Reset static state on each instantiation
            self::$voidCalled = false;
            self::$refundCalled = false;
            self::$voidParams = null;
        }

        public function getName(): string
        {
            return 'cancel_spy';
        }

        public function purchase(array $params): array
        {
            return [
                'success' => true,
                'transaction_id' => 'spy_ch_'.uniqid(),
                'message' => 'ok',
                'code' => 'ok',
                'data' => [],
            ];
        }

        public function authorize(array $params): array
        {
            return [
                'success' => true,
                'transaction_id' => 'spy_auth_'.uniqid(),
                'message' => 'ok',
                'code' => 'ok',
                'data' => [],
            ];
        }

        public function capture(array $params): array
        {
            return ['success' => true, 'transaction_id' => 'spy_cap_001'];
        }

        public function refund(array $params): array
        {
            self::$refundCalled = true;

            return ['success' => true, 'transaction_id' => 'spy_ref_001'];
        }

        public function void(array $params): array
        {
            self::$voidCalled = true;
            self::$voidParams = $params;

            return ['success' => true, 'transaction_id' => 'spy_void_001'];
        }

        public function verifyWebhookSignature(string $payload, array $headers): bool
        {
            return true;
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
            return ['success' => true, 'transaction_id' => $params['transaction_id'] ?? 'test', 'code' => 'ok', 'data' => ['status' => 'succeeded']];
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

    ConnectorFactory::register('cancel_spy', get_class($spyClass));
    $this->spyConnectorClass = get_class($spyClass);

    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'cancel_spy',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);
});

function cancelApiHeaders(): array
{
    return ['api-key' => test()->rawKey];
}

function createAndAuthorize(int $amount = 5000): string
{
    $create = test()->postJson('/api/v1/payments', [
        'amount' => $amount,
        'currency' => 'USD',
        'capture_method' => 'manual',
    ], cancelApiHeaders());

    $create->assertStatus(201);
    $paymentId = $create->json('data.id');

    $confirm = test()->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => [
            'card' => [
                'card_number' => '4242424242424242',
                'card_exp_month' => '12',
                'card_exp_year' => '2030',
                'card_cvc' => '123',
            ],
        ],
    ], cancelApiHeaders());

    $confirm->assertOk()
        ->assertJsonPath('data.attributes.status', 'requires_capture');

    return $paymentId;
}

test('cancel authorized payment calls void, not refund', function () {
    $paymentId = createAndAuthorize();

    $response = $this->postJson("/api/v1/payments/{$paymentId}/cancel", [], cancelApiHeaders());

    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'cancelled');

    $spyClass = $this->spyConnectorClass;
    expect($spyClass::$voidCalled)->toBeTrue();
    expect($spyClass::$refundCalled)->toBeFalse();
});

test('void receives correct transaction_id and payment_id', function () {
    $paymentId = createAndAuthorize();

    $this->postJson("/api/v1/payments/{$paymentId}/cancel", [], cancelApiHeaders());

    $spyClass = $this->spyConnectorClass;
    expect($spyClass::$voidParams)->toBeArray();
    expect($spyClass::$voidParams['transaction_id'])->toStartWith('spy_auth_');
    expect($spyClass::$voidParams['payment_id'])->toBe($paymentId);
});

test('cancel succeeds even when void throws exception', function () {
    // Register a connector whose void() throws
    $throwingVoidClass = new class([]) implements ConnectorInterface
    {
        public function __construct(?array $credentials = []) {}

        public function getName(): string
        {
            return 'throw_void';
        }

        public function purchase(array $params): array
        {
            return ['success' => true, 'transaction_id' => 'tv_ch_'.uniqid(), 'message' => 'ok', 'code' => 'ok', 'data' => []];
        }

        public function authorize(array $params): array
        {
            return ['success' => true, 'transaction_id' => 'tv_auth_'.uniqid(), 'message' => 'ok', 'code' => 'ok', 'data' => []];
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
            throw new RuntimeException('PSP void endpoint is down');
        }

        public function verifyWebhookSignature(string $payload, array $headers): bool
        {
            return true;
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

    ConnectorFactory::register('throw_void', get_class($throwingVoidClass));

    // Replace the connector MCA
    MerchantConnectorAccount::where('merchant_account_id', $this->merchant->id)
        ->where('connector_name', 'cancel_spy')
        ->update(['connector_name' => 'throw_void']);

    // Create and authorize with this connector
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 5000,
        'currency' => 'USD',
        'capture_method' => 'manual',
    ], cancelApiHeaders());

    $paymentId = $create->json('data.id');

    $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => [
            'card' => [
                'card_number' => '4242424242424242',
                'card_exp_month' => '12',
                'card_exp_year' => '2030',
                'card_cvc' => '123',
            ],
        ],
    ], cancelApiHeaders());

    // Cancel should still succeed despite void() throwing
    $response = $this->postJson("/api/v1/payments/{$paymentId}/cancel", [], cancelApiHeaders());

    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'cancelled');
});
