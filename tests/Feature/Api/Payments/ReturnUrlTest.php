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

    // Spy connector that records the params it received
    $spyConnectorClass = new class([]) implements ConnectorInterface
    {
        public static array $lastParams = [];

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
            return 'spy';
        }

        public function authorize(array $params): array
        {
            self::$lastParams = $params;

            return [
                'success' => true,
                'transaction_id' => 'spy_auth_001',
                'message' => 'ok',
                'code' => 'ok',
            ];
        }

        public function purchase(array $params): array
        {
            self::$lastParams = $params;

            return [
                'success' => true,
                'transaction_id' => 'spy_ch_001',
                'message' => 'ok',
                'code' => 'ok',
            ];
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

    ConnectorFactory::register('spy', get_class($spyConnectorClass));

    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'spy',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    $this->spyConnectorClass = get_class($spyConnectorClass);
});

function returnUrlApiHeaders(): array
{
    return ['api-key' => test()->rawKey];
}

function createReturnUrlPayment(array $attrs = []): TestResponse
{
    return test()->postJson('/api/v1/payments', array_merge([
        'amount' => 5000,
        'currency' => 'USD',
    ], $attrs), returnUrlApiHeaders());
}

test('return_url gets payment_id appended as query parameter', function () {
    $create = createReturnUrlPayment(['return_url' => 'https://merchant.com/return']);
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
    ], returnUrlApiHeaders());

    $spyClass = $this->spyConnectorClass;
    $receivedUrl = $spyClass::$lastParams['return_url'] ?? null;

    expect($receivedUrl)->toBe("https://merchant.com/return?payment_id={$paymentId}");
});

test('return_url with existing query params gets payment_id appended', function () {
    $create = createReturnUrlPayment(['return_url' => 'https://merchant.com/return?session=abc']);
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
    ], returnUrlApiHeaders());

    $spyClass = $this->spyConnectorClass;
    $receivedUrl = $spyClass::$lastParams['return_url'] ?? null;

    expect($receivedUrl)->toBe("https://merchant.com/return?session=abc&payment_id={$paymentId}");
});

test('null return_url is passed as null to connector', function () {
    $create = createReturnUrlPayment(); // no return_url
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
    ], returnUrlApiHeaders());

    $response->assertOk();

    $spyClass = $this->spyConnectorClass;
    expect($spyClass::$lastParams)->toHaveKey('return_url');
    expect($spyClass::$lastParams['return_url'])->toBeNull();
});
