<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
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
use Streeboga\PaymentData\Models\PaymentAttempt;
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

function syncApiHeaders(): array
{
    return ['api-key' => test()->rawKey];
}

function createPaymentInStatus(PaymentStatus $status, ?string $connectorTxnId = 'test_txn_123'): PaymentIntent
{
    $payment = PaymentIntent::create([
        'merchant_account_id' => test()->merchant->id,
        'amount' => 5000,
        'currency' => 'USD',
        'status' => $status,
        'capture_method' => 'automatic',
        'authentication_type' => 'three_ds',
        'attempt_count' => 1,
        'connector' => 'test',
    ]);

    if ($connectorTxnId) {
        PaymentAttempt::create([
            'payment_intent_id' => $payment->id,
            'connector' => 'test',
            'status' => 'pending',
            'connector_transaction_id' => $connectorTxnId,
            'amount' => 5000,
        ]);
    }

    return $payment;
}

test('sync updates requires_customer_action to succeeded', function () {
    $payment = createPaymentInStatus(PaymentStatus::RequiresCustomerAction);

    $response = $this->postJson("/api/v1/payments/{$payment->key}/sync", [], syncApiHeaders());

    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'succeeded');

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Succeeded);
    expect($payment->amount_received)->toBe(5000);
});

test('sync updates processing to succeeded', function () {
    $payment = createPaymentInStatus(PaymentStatus::Processing);

    $response = $this->postJson("/api/v1/payments/{$payment->key}/sync", [], syncApiHeaders());

    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'succeeded');

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Succeeded);
});

test('sync on already succeeded returns 400', function () {
    $payment = createPaymentInStatus(PaymentStatus::Succeeded);

    $response = $this->postJson("/api/v1/payments/{$payment->key}/sync", [], syncApiHeaders());

    $response->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'invalid_state');
});

test('sync on requires_payment_method returns 400', function () {
    $payment = createPaymentInStatus(PaymentStatus::RequiresPaymentMethod);

    $response = $this->postJson("/api/v1/payments/{$payment->key}/sync", [], syncApiHeaders());

    $response->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'invalid_state');
});

test('sync with no transaction returns 400', function () {
    $payment = createPaymentInStatus(PaymentStatus::RequiresCustomerAction, connectorTxnId: null);

    $response = $this->postJson("/api/v1/payments/{$payment->key}/sync", [], syncApiHeaders());

    $response->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'no_transaction');
});

test('sync when PSP returns unknown status leaves payment unchanged', function () {
    // Register a connector that returns a pending/unknown status
    $pendingConnectorClass = new class([]) implements ConnectorInterface
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
            return 'pending_psp';
        }

        public function purchase(array $params): array
        {
            return ['success' => true, 'transaction_id' => 'x', 'message' => 'ok', 'code' => 'ok', 'data' => []];
        }

        public function authorize(array $params): array
        {
            return ['success' => true, 'transaction_id' => 'x', 'message' => 'ok', 'code' => 'ok', 'data' => []];
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
            return [
                'success' => true,
                'transaction_id' => $params['transaction_id'] ?? 'x',
                'code' => 'ok',
                'data' => ['status' => 'pending'],
            ];
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

    ConnectorFactory::register('pending_psp', get_class($pendingConnectorClass));

    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'pending_psp',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000,
        'currency' => 'USD',
        'status' => PaymentStatus::Processing,
        'capture_method' => 'automatic',
        'authentication_type' => 'three_ds',
        'attempt_count' => 1,
        'connector' => 'pending_psp',
    ]);

    PaymentAttempt::create([
        'payment_intent_id' => $payment->id,
        'connector' => 'pending_psp',
        'status' => 'pending',
        'connector_transaction_id' => 'pending_txn_123',
        'amount' => 5000,
    ]);

    $response = $this->postJson("/api/v1/payments/{$payment->key}/sync", [], syncApiHeaders());

    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'processing');

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Processing);
});
