<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentConnectors\ConnectorFactory;
use Streeboga\PaymentData\Contracts\ConnectorInterface;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\Customer;
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

    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'spy',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test_xxx'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);
});

function diApiHeaders(): array
{
    return ['api-key' => test()->rawKey];
}

function createAndAuthorizePayment(int $amount = 10000, string $currency = 'USD'): string
{
    $create = test()->postJson('/api/v1/payments', [
        'amount' => $amount,
        'currency' => $currency,
        'capture_method' => 'manual',
    ], diApiHeaders());

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
    ], diApiHeaders());

    $confirm->assertOk()
        ->assertJsonPath('data.attributes.status', 'requires_capture');

    return $paymentId;
}

// BUG #10: capture must pass currency to connector
test('capture passes currency to connector', function () {
    // Register a spy connector that records capture params
    SpyConnector::$lastCaptureParams = null;
    ConnectorFactory::register('spy', SpyConnector::class);

    $paymentId = createAndAuthorizePayment(10000, 'EUR');

    $response = $this->postJson("/api/v1/payments/{$paymentId}/capture", [
        'amount_to_capture' => 5000,
    ], diApiHeaders());

    $response->assertOk();
    expect(SpyConnector::$lastCaptureParams)->toBeArray();
    expect(SpyConnector::$lastCaptureParams)->toHaveKey('currency', 'EUR');
});

// BUG #13: create must reject customer_id that does not belong to merchant
test('create rejects customer_id not belonging to merchant', function () {
    // Create a different merchant with its own customer
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $otherMerchant = MerchantAccount::create(['org_id' => $otherOrg->id, 'name' => 'Other']);

    $otherCustomer = Customer::create([
        'merchant_account_id' => $otherMerchant->id,
        'name' => 'Other Customer',
    ]);

    $response = $this->postJson('/api/v1/payments', [
        'amount' => 5000,
        'currency' => 'USD',
        'customer_id' => $otherCustomer->key,
    ], diApiHeaders());

    $response->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'customer_not_found');
});

/**
 * A test connector that records capture params for assertion.
 */
class SpyConnector implements ConnectorInterface
{
    public static ?array $lastCaptureParams = null;

    public function __construct(?array $credentials = []) {}

    public function getName(): string
    {
        return 'spy';
    }

    public function purchase(array $params): array
    {
        return $this->success($params);
    }

    public function authorize(array $params): array
    {
        return $this->success($params);
    }

    public function capture(array $params): array
    {
        self::$lastCaptureParams = $params;

        return [
            'success' => true,
            'transaction_id' => 'spy_cap_123',
            'message' => 'Capture successful',
            'code' => 'ok',
        ];
    }

    public function refund(array $params): array
    {
        return [
            'success' => true,
            'transaction_id' => 'spy_ref_123',
            'message' => 'Refund successful',
            'code' => 'ok',
        ];
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
        return $payload['payment_id'] ?? null;
    }

    private function success(array $params): array
    {
        return [
            'success' => true,
            'transaction_id' => 'spy_txn_'.uniqid(),
            'message' => 'Success',
            'code' => 'ok',
            'data' => [],
        ];
    }
}
