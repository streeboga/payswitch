<?php

declare(strict_types=1);

use App\DataTransferObjects\Payment\ConfirmPaymentData;
use App\Services\PaymentConfirmationService;
use Carbon\Carbon;
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
    $this->service = app(PaymentConfirmationService::class);
});

function createMcaForConfirm(object $context, string $connectorName = 'fake_session'): MerchantConnectorAccount
{
    return MerchantConnectorAccount::create([
        'merchant_account_id' => $context->merchant->id,
        'business_profile_id' => $context->profile->id,
        'connector_name' => $connectorName,
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['api_key' => 'test'],
        'payment_methods_enabled' => [['payment_method' => 'card'], ['payment_method' => 'sbp']],
        'test_mode' => true,
    ]);
}

function createPaymentForConfirmV2(object $context): PaymentIntent
{
    return PaymentIntent::create([
        'merchant_account_id' => $context->merchant->id,
        'business_profile_id' => $context->profile->id,
        'amount' => 10000,
        'currency' => 'RUB',
        'status' => PaymentStatus::RequiresPaymentMethod,
        'capture_method' => CaptureMethod::Automatic,
        'return_url' => 'https://merchant.com/return',
    ]);
}

// --- Legacy array flow ---

test('legacy array flow with redirect_url still works', function () {
    ConnectorFactory::register('fake_legacy', FakeLegacyRedirectConnector::class);
    createMcaForConfirm($this, 'fake_legacy');
    $payment = createPaymentForConfirmV2($this);

    $result = $this->service->confirm($payment->key, new ConfirmPaymentData(payment_method: 'card'), $this->merchant->id);

    expect($result->status)->toBe(PaymentStatus::RequiresCustomerAction);
    expect($result->metadata)->toHaveKey('redirect_url', 'https://psp.com/pay/123');
    expect($result->metadata)->toHaveKey('redirect_method', 'GET');
    expect($result->metadata)->toHaveKey('session_id', 'sess_123');
});

// --- PaymentSessionResult: serverRedirect ---

test('PaymentSessionResult::serverRedirect stores type=redirect in metadata', function () {
    ConnectorFactory::register('fake_session', FakeServerRedirectConnector::class);
    createMcaForConfirm($this);
    $payment = createPaymentForConfirmV2($this);

    $result = $this->service->confirm($payment->key, new ConfirmPaymentData(payment_method: 'card'), $this->merchant->id);

    expect($result->status)->toBe(PaymentStatus::RequiresCustomerAction);
    expect($result->metadata)->toHaveKey('type', 'redirect');
    expect($result->metadata)->toHaveKey('url', 'https://psp.com/redirect/abc');
    expect($result->metadata)->toHaveKey('method', 'GET');
    expect($result->metadata)->toHaveKey('transaction_id', 'txn_redirect_1');
});

// --- PaymentSessionResult: formRedirect ---

test('PaymentSessionResult::formRedirect stores form url and params in metadata', function () {
    ConnectorFactory::register('fake_session', FakeFormRedirectConnector::class);
    createMcaForConfirm($this);
    $payment = createPaymentForConfirmV2($this);

    $result = $this->service->confirm($payment->key, new ConfirmPaymentData(payment_method: 'card'), $this->merchant->id);

    expect($result->status)->toBe(PaymentStatus::RequiresCustomerAction);
    expect($result->metadata)->toHaveKey('type', 'form_redirect');
    expect($result->metadata)->toHaveKey('url', 'https://psp.com/form');
    expect($result->metadata)->toHaveKey('params', ['token' => 'abc', 'order' => '123']);
    expect($result->metadata)->toHaveKey('method', 'POST');
});

// --- PaymentSessionResult: embeddedWidget ---

test('PaymentSessionResult::embeddedWidget stores widget provider and params in metadata', function () {
    ConnectorFactory::register('fake_session', FakeEmbeddedWidgetConnector::class);
    createMcaForConfirm($this);
    $payment = createPaymentForConfirmV2($this);

    $result = $this->service->confirm($payment->key, new ConfirmPaymentData(payment_method: 'card'), $this->merchant->id);

    expect($result->status)->toBe(PaymentStatus::RequiresCustomerAction);
    expect($result->metadata)->toHaveKey('type', 'widget');
    expect($result->metadata)->toHaveKey('provider', 'cloudpayments');
    expect($result->metadata)->toHaveKey('script_url', 'https://widget.cloudpayments.ru/bundles/checkout.js');
    expect($result->metadata)->toHaveKey('params', ['publicId' => 'pk_xxx']);
    expect($result->metadata)->toHaveKey('transaction_id', 'txn_widget_1');
});

// --- PaymentSessionResult: qrInline ---

test('PaymentSessionResult::qrInline stores qr_data and format in metadata', function () {
    ConnectorFactory::register('fake_session', FakeQrInlineConnector::class);
    createMcaForConfirm($this);
    $payment = createPaymentForConfirmV2($this);

    $result = $this->service->confirm($payment->key, new ConfirmPaymentData(payment_method: 'sbp'), $this->merchant->id);

    expect($result->status)->toBe(PaymentStatus::RequiresCustomerAction);
    expect($result->metadata)->toHaveKey('type', 'qr');
    expect($result->metadata)->toHaveKey('qr_data', 'https://qr.nspk.ru/AS1234567890');
    expect($result->metadata)->toHaveKey('format', 'url');
    expect($result->metadata)->toHaveKey('payment_id', 'sbp_pay_1');
    expect($result->metadata)->toHaveKey('transaction_id', 'txn_qr_1');
});

// --- Payment attempt creation ---

test('payment attempt is created with correct connector_transaction_id from PaymentSessionResult', function () {
    ConnectorFactory::register('fake_session', FakeServerRedirectConnector::class);
    createMcaForConfirm($this);
    $payment = createPaymentForConfirmV2($this);

    $this->service->confirm($payment->key, new ConfirmPaymentData(payment_method: 'card'), $this->merchant->id);

    $this->assertDatabaseHas('payment_attempts', [
        'connector' => 'fake_session',
        'connector_transaction_id' => 'txn_redirect_1',
        'status' => 'requires_action',
        'amount' => 10000,
    ]);
});

test('payment status transitions to RequiresCustomerAction for all session result types', function () {
    ConnectorFactory::register('fake_session', FakeQrInlineConnector::class);
    createMcaForConfirm($this);
    $payment = createPaymentForConfirmV2($this);

    $result = $this->service->confirm($payment->key, new ConfirmPaymentData(payment_method: 'sbp'), $this->merchant->id);

    expect($result->status)->toBe(PaymentStatus::RequiresCustomerAction);

    $this->assertDatabaseHas('payment_intents', [
        'key' => $payment->key,
        'status' => PaymentStatus::RequiresCustomerAction->value,
    ]);
});

// --- Fake connector stubs ---

// Shared stub methods via a trait
trait FakeConnectorStubs
{
    public function __construct(private array $credentials = []) {}

    public static function capabilities(): ConnectorCapabilities
    {
        return new ConnectorCapabilities(
            defaultDisplayName: ['en' => 'Fake'],
            logoPath: '',
            directMethods: [],
            fallbackSessionType: SessionResultType::ServerRedirect,
            amountUnit: AmountUnit::MinorUnits,
        );
    }

    public function authorize(array $params): array
    {
        return ['success' => true];
    }

    public function purchase(array $params): array
    {
        return ['success' => true];
    }

    public function capture(array $params): array
    {
        return ['success' => true];
    }

    public function refund(array $params): array
    {
        return ['success' => true];
    }

    public function void(array $params): array
    {
        return ['success' => true];
    }

    public function testConnection(): array
    {
        return ['success' => true];
    }

    public function parseWebhook(array $payload, array $headers = []): array
    {
        return [];
    }

    public function getName(): string
    {
        return 'fake';
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
        return ['success' => false];
    }

    public function mapPaymentStatusToInternal(string $rawStatus): ?PaymentStatus
    {
        return null;
    }
}

class FakeLegacyRedirectConnector implements ConnectorInterface
{
    use FakeConnectorStubs;

    public function createPaymentSession(array $params): PaymentSessionResult|array
    {
        return [
            'redirect_url' => 'https://psp.com/pay/123',
            'session_id' => 'sess_123',
            'transaction_id' => 'txn_legacy_1',
        ];
    }
}

class FakeServerRedirectConnector implements ConnectorInterface
{
    use FakeConnectorStubs;

    public function createPaymentSession(array $params): PaymentSessionResult|array
    {
        return PaymentSessionResult::serverRedirect('https://psp.com/redirect/abc', 'GET', 'txn_redirect_1');
    }
}

class FakeFormRedirectConnector implements ConnectorInterface
{
    use FakeConnectorStubs;

    public function createPaymentSession(array $params): PaymentSessionResult|array
    {
        return PaymentSessionResult::formRedirect('https://psp.com/form', ['token' => 'abc', 'order' => '123'], 'POST');
    }
}

class FakeEmbeddedWidgetConnector implements ConnectorInterface
{
    use FakeConnectorStubs;

    public function createPaymentSession(array $params): PaymentSessionResult|array
    {
        return PaymentSessionResult::embeddedWidget(
            'cloudpayments',
            'https://widget.cloudpayments.ru/bundles/checkout.js',
            ['publicId' => 'pk_xxx'],
            'txn_widget_1',
        );
    }
}

class FakeQrInlineConnector implements ConnectorInterface
{
    use FakeConnectorStubs;

    public function createPaymentSession(array $params): PaymentSessionResult|array
    {
        return PaymentSessionResult::qrInline(
            'https://qr.nspk.ru/AS1234567890',
            'url',
            'sbp_pay_1',
            Carbon::parse('2026-03-23T12:00:00Z'),
            'txn_qr_1',
        );
    }
}
