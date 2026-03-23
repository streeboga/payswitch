<?php

declare(strict_types=1);

use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentConnectors\ConnectorCapabilities;
use Streeboga\PaymentConnectors\ConnectorFactory;
use Streeboga\PaymentConnectors\PaymentSessionResult;
use Streeboga\PaymentData\Contracts\ConnectorInterface;
use Streeboga\PaymentData\Enums\AmountUnit;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\SessionResultType;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
    $this->service = app(PaymentService::class);
});

function createPaymentForMethodsTest(object $context): PaymentIntent
{
    return PaymentIntent::create([
        'merchant_account_id' => $context->merchant->id,
        'business_profile_id' => $context->profile->id,
        'amount' => 10000,
        'currency' => 'RUB',
        'status' => PaymentStatus::RequiresPaymentMethod,
        'capture_method' => CaptureMethod::Automatic,
    ]);
}

test('single connector with direct methods returns direct_methods mode', function () {
    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'yookassa',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['shop_id' => '123'],
        'payment_methods_enabled' => ['card', 'sbp'],
        'test_mode' => true,
    ]);

    $payment = createPaymentForMethodsTest($this);
    $result = $this->service->getAvailablePaymentMethods($payment, 'ru');

    expect($result)
        ->toBeArray()
        ->toHaveKeys(['mode', 'methods', 'connectors']);

    expect($result['mode'])->toBe('direct_methods');
    expect($result['methods'])->toHaveCount(2);
    expect($result['connectors'])->toBeEmpty();

    $card = collect($result['methods'])->firstWhere('method', 'card');
    expect($card)
        ->toHaveKey('type', 'direct')
        ->toHaveKey('connector', 'yookassa')
        ->toHaveKey('session_type', 'redirect');

    $sbp = collect($result['methods'])->firstWhere('method', 'sbp');
    expect($sbp)
        ->toHaveKey('type', 'direct')
        ->toHaveKey('method', 'sbp');
});

test('connector without direct methods for enabled method returns connector_selection mode', function () {
    // Register a fake connector that has NO direct methods
    ConnectorFactory::register('robokassa', FakeNonDirectConnector::class);

    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'robokassa',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['key' => 'test'],
        'payment_methods_enabled' => ['card', 'qiwi'],
        'test_mode' => true,
    ]);

    $payment = createPaymentForMethodsTest($this);
    $result = $this->service->getAvailablePaymentMethods($payment, 'ru');

    expect($result['mode'])->toBe('connector_selection');
    expect($result['methods'])->toBeEmpty();
    expect($result['connectors'])->toHaveCount(1);
    expect($result['connectors'][0])
        ->toHaveKey('connector_name', 'robokassa')
        ->toHaveKey('session_type', 'form_redirect');
});

test('multiple connectors producing mixed mode', function () {
    ConnectorFactory::register('robokassa', FakeNonDirectConnector::class);

    // YooKassa supports direct card
    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'yookassa',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['shop_id' => '123'],
        'payment_methods_enabled' => ['card'],
        'test_mode' => true,
    ]);

    // Robokassa has no direct methods
    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'robokassa',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['key' => 'test'],
        'payment_methods_enabled' => ['qiwi'],
        'test_mode' => true,
    ]);

    $payment = createPaymentForMethodsTest($this);
    $result = $this->service->getAvailablePaymentMethods($payment, 'ru');

    expect($result['mode'])->toBe('mixed');
    expect($result['methods'])->not->toBeEmpty();
    expect($result['connectors'])->not->toBeEmpty();
});

test('display_config overrides display_name and logo_url', function () {
    ConnectorFactory::register('robokassa', FakeNonDirectConnector::class);

    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'robokassa',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['key' => 'test'],
        'payment_methods_enabled' => ['card'],
        'display_config' => [
            'display_name' => 'Custom Robo Name',
            'logo_url' => '/custom/robo-logo.png',
        ],
        'test_mode' => true,
    ]);

    $payment = createPaymentForMethodsTest($this);
    $result = $this->service->getAvailablePaymentMethods($payment, 'ru');

    expect($result['connectors'][0])
        ->toHaveKey('display_name', 'Custom Robo Name')
        ->toHaveKey('logo_url', '/custom/robo-logo.png');
});

test('locale fallback from ru to en', function () {
    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'yookassa',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['shop_id' => '123'],
        'payment_methods_enabled' => ['card'],
        'test_mode' => true,
    ]);

    $payment = createPaymentForMethodsTest($this);

    $resultRu = $this->service->getAvailablePaymentMethods($payment, 'ru');
    $resultEn = $this->service->getAvailablePaymentMethods($payment, 'en');

    $cardRu = collect($resultRu['methods'])->firstWhere('method', 'card');
    $cardEn = collect($resultEn['methods'])->firstWhere('method', 'card');

    expect($cardRu['display_name'])->toBe('Банковская карта');
    expect($cardEn['display_name'])->toBe('Card');
});

test('empty connectors returns mode none', function () {
    $payment = createPaymentForMethodsTest($this);
    $result = $this->service->getAvailablePaymentMethods($payment, 'ru');

    expect($result['mode'])->toBe('none');
    expect($result['methods'])->toBeEmpty();
    expect($result['connectors'])->toBeEmpty();
});

test('first connector wins for duplicate direct methods', function () {
    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'yookassa',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['shop_id' => '123'],
        'payment_methods_enabled' => ['card'],
        'test_mode' => true,
    ]);

    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'stripe',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['api_key' => 'sk_test'],
        'payment_methods_enabled' => ['card'],
        'test_mode' => true,
    ]);

    $payment = createPaymentForMethodsTest($this);
    $result = $this->service->getAvailablePaymentMethods($payment, 'ru');

    // Only one card entry — first connector wins
    $cards = collect($result['methods'])->where('method', 'card');
    expect($cards)->toHaveCount(1);
    expect($cards->first()['connector'])->toBe('yookassa');
});

test('handles payment_methods_enabled as array of objects', function () {
    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'test',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => [],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    $payment = createPaymentForMethodsTest($this);
    $result = $this->service->getAvailablePaymentMethods($payment, 'en');

    expect($result['mode'])->toBe('direct_methods');
    expect($result['methods'])->toHaveCount(1);
    expect($result['methods'][0]['method'])->toBe('card');
});

// Fake connector with no direct methods for testing connector_selection mode
class FakeNonDirectConnector implements ConnectorInterface
{
    public function __construct(?array $credentials = []) {}

    public static function capabilities(): ConnectorCapabilities
    {
        return new ConnectorCapabilities(
            defaultDisplayName: ['ru' => 'Робокасса', 'en' => 'Robokassa'],
            logoPath: '/logos/robokassa.svg',
            directMethods: [],
            fallbackSessionType: SessionResultType::FormRedirect,
            amountUnit: AmountUnit::Rubles,
        );
    }

    public function getName(): string
    {
        return 'robokassa';
    }

    public function authorize(array $params): array
    {
        return [];
    }

    public function purchase(array $params): array
    {
        return [];
    }

    public function capture(array $params): array
    {
        return [];
    }

    public function refund(array $params): array
    {
        return [];
    }

    public function void(array $params): array
    {
        return [];
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
        return [];
    }

    public function mapPaymentStatusToInternal(string $rawStatus): ?PaymentStatus
    {
        return null;
    }

    public function createPaymentSession(array $params): PaymentSessionResult|array
    {
        return [];
    }

    public function testConnection(): array
    {
        return ['success' => true, 'message' => 'ok'];
    }
}
