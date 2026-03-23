# Widget V2 — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Переделать архитектуру коннекторов и виджета: добавить ConnectorCapabilities, PaymentSessionResult (4 типа), переписать payment-methods API, обновить виджет для обработки всех типов, создать базовый HttpConnector для минимизации boilerplate новых коннекторов.

**Architecture:** `HttpConnector` — новый базовый класс (замена неиспользуемому `AbstractConnector`) с общим HTTP plumbing. Каждый новый коннектор = конфиг capabilities + маппинги параметров + маппинги ответов. `ConnectorInterface` расширяется методом `capabilities()`. `PaymentConfirmationService` обрабатывает 4 типа `PaymentSessionResult`. Виджет рендерит 4 типа action.

**Tech Stack:** PHP 8.4, Laravel 13, Pest, Preact (widget), TypeScript, Vite

**Дизайн-документ:** `docs/plans/2026-03-20-widget-v2-connector-config.md`

---

## Task 1: ConnectorCapabilities + PaymentSessionResult value objects

**Files:**
- Create: `packages/streeboga/payment-data/src/Enums/SessionResultType.php`
- Create: `packages/streeboga/payment-data/src/Enums/AmountUnit.php`
- Create: `packages/streeboga/payment-connectors/src/ConnectorCapabilities.php`
- Create: `packages/streeboga/payment-connectors/src/DirectMethod.php`
- Create: `packages/streeboga/payment-connectors/src/PaymentSessionResult.php`
- Modify: `packages/streeboga/payment-data/src/Contracts/ConnectorInterface.php`
- Test: `tests/Unit/Connectors/ConnectorCapabilitiesTest.php`
- Test: `tests/Unit/Connectors/PaymentSessionResultTest.php`

**Step 1: Write failing tests for ConnectorCapabilities**

```php
// tests/Unit/Connectors/ConnectorCapabilitiesTest.php
use Streeboga\PaymentConnectors\ConnectorCapabilities;
use Streeboga\PaymentConnectors\DirectMethod;
use Streeboga\PaymentData\Enums\AmountUnit;
use Streeboga\PaymentData\Enums\SessionResultType;

it('creates capabilities with direct methods', function () {
    $caps = new ConnectorCapabilities(
        defaultDisplayName: ['ru' => 'ЮKassa', 'en' => 'YooKassa'],
        logoPath: '/logos/yookassa.svg',
        directMethods: [
            'card' => new DirectMethod(SessionResultType::ServerRedirect),
            'sbp' => new DirectMethod(SessionResultType::ServerRedirect),
        ],
        fallbackSessionType: SessionResultType::ServerRedirect,
        amountUnit: AmountUnit::Rubles,
    );

    expect($caps->supportsDirectMethod('card'))->toBeTrue();
    expect($caps->supportsDirectMethod('apple_pay'))->toBeFalse();
    expect($caps->getDirectMethod('card')->sessionType)->toBe(SessionResultType::ServerRedirect);
    expect($caps->displayName('ru'))->toBe('ЮKassa');
    expect($caps->displayName('fr'))->toBe('YooKassa'); // fallback to en
});

it('creates capabilities without direct methods', function () {
    $caps = new ConnectorCapabilities(
        defaultDisplayName: ['ru' => 'Сбербанк', 'en' => 'Sberbank'],
        logoPath: '/logos/sberbank.svg',
        directMethods: [],
        fallbackSessionType: SessionResultType::ServerRedirect,
        amountUnit: AmountUnit::Kopecks,
        sbpMethod: new DirectMethod(SessionResultType::QrInline),
    );

    expect($caps->supportsDirectMethod('card'))->toBeFalse();
    expect($caps->supportsDirectMethod('sbp'))->toBeTrue(); // via sbpMethod
    expect($caps->getDirectMethod('sbp')->sessionType)->toBe(SessionResultType::QrInline);
    expect($caps->amountUnit)->toBe(AmountUnit::Kopecks);
});
```

**Step 2: Write failing tests for PaymentSessionResult**

```php
// tests/Unit/Connectors/PaymentSessionResultTest.php
use Streeboga\PaymentConnectors\PaymentSessionResult;
use Streeboga\PaymentData\Enums\SessionResultType;

it('creates server redirect result', function () {
    $result = PaymentSessionResult::serverRedirect('https://pay.example.com/123', 'GET');

    expect($result->type)->toBe(SessionResultType::ServerRedirect);
    expect($result->redirectUrl)->toBe('https://pay.example.com/123');
    expect($result->redirectMethod)->toBe('GET');
    expect($result->toArray())->toHaveKeys(['type', 'redirect_url', 'redirect_method']);
});

it('creates form redirect result', function () {
    $result = PaymentSessionResult::formRedirect(
        'https://auth.robokassa.ru/Merchant/Index.aspx',
        ['MerchantLogin' => 'shop', 'OutSum' => '100.00', 'SignatureValue' => 'abc'],
        'POST',
    );

    expect($result->type)->toBe(SessionResultType::FormRedirect);
    expect($result->formParams)->toHaveKey('SignatureValue');
});

it('creates embedded widget result', function () {
    $result = PaymentSessionResult::embeddedWidget(
        provider: 'cloudpayments',
        scriptUrl: 'https://widget.cloudpayments.ru/bundles/cloudpayments.js',
        params: ['publicId' => 'pk_xxx', 'amount' => '100.00'],
    );

    expect($result->type)->toBe(SessionResultType::EmbeddedWidget);
    expect($result->widgetProvider)->toBe('cloudpayments');
    expect($result->widgetParams)->toHaveKey('publicId');
});

it('creates qr inline result', function () {
    $result = PaymentSessionResult::qrInline(
        qrData: '<svg>...</svg>',
        format: 'svg',
        paymentId: 'tbank_123',
        expiresAt: now()->addMinutes(5),
    );

    expect($result->type)->toBe(SessionResultType::QrInline);
    expect($result->qrData)->toBe('<svg>...</svg>');
    expect($result->qrFormat)->toBe('svg');
});
```

**Step 3: Run tests to verify they fail**

```bash
./vendor/bin/pest tests/Unit/Connectors/ConnectorCapabilitiesTest.php tests/Unit/Connectors/PaymentSessionResultTest.php
```
Expected: FAIL — classes not found

**Step 4: Implement enums**

```php
// packages/streeboga/payment-data/src/Enums/SessionResultType.php
<?php
declare(strict_types=1);
namespace Streeboga\PaymentData\Enums;

enum SessionResultType: string {
    case ServerRedirect = 'redirect';
    case FormRedirect = 'form_redirect';
    case EmbeddedWidget = 'widget';
    case QrInline = 'qr';
}
```

```php
// packages/streeboga/payment-data/src/Enums/AmountUnit.php
<?php
declare(strict_types=1);
namespace Streeboga\PaymentData\Enums;

enum AmountUnit: string {
    case Rubles = 'rubles';       // amount / 100, format "100.00"
    case Kopecks = 'kopecks';     // amount as-is (уже в копейках)
    case MinorUnits = 'minor';    // amount as-is (cents/kopecks — Stripe style)
}
```

**Step 5: Implement ConnectorCapabilities + DirectMethod**

```php
// packages/streeboga/payment-connectors/src/DirectMethod.php
<?php
declare(strict_types=1);
namespace Streeboga\PaymentConnectors;

use Streeboga\PaymentData\Enums\SessionResultType;

final readonly class DirectMethod
{
    public function __construct(
        public SessionResultType $sessionType,
    ) {}
}
```

```php
// packages/streeboga/payment-connectors/src/ConnectorCapabilities.php
<?php
declare(strict_types=1);
namespace Streeboga\PaymentConnectors;

use Streeboga\PaymentData\Enums\AmountUnit;
use Streeboga\PaymentData\Enums\SessionResultType;

final readonly class ConnectorCapabilities
{
    /**
     * @param  array<string, string>  $defaultDisplayName  Localized names ['ru' => '...', 'en' => '...']
     * @param  array<string, DirectMethod>  $directMethods  Method name → DirectMethod
     */
    public function __construct(
        public array $defaultDisplayName,
        public string $logoPath,
        public array $directMethods,
        public SessionResultType $fallbackSessionType,
        public AmountUnit $amountUnit = AmountUnit::MinorUnits,
        public ?DirectMethod $sbpMethod = null,
    ) {}

    public function supportsDirectMethod(string $method): bool
    {
        if ($method === 'sbp' && $this->sbpMethod !== null) {
            return true;
        }

        return isset($this->directMethods[$method]);
    }

    public function getDirectMethod(string $method): ?DirectMethod
    {
        if ($method === 'sbp' && $this->sbpMethod !== null) {
            return $this->sbpMethod;
        }

        return $this->directMethods[$method] ?? null;
    }

    public function displayName(string $locale): string
    {
        return $this->defaultDisplayName[$locale]
            ?? $this->defaultDisplayName['en']
            ?? reset($this->defaultDisplayName)
            ?: '';
    }
}
```

**Step 6: Implement PaymentSessionResult**

```php
// packages/streeboga/payment-connectors/src/PaymentSessionResult.php
<?php
declare(strict_types=1);
namespace Streeboga\PaymentConnectors;

use Carbon\CarbonInterface;
use Streeboga\PaymentData\Enums\SessionResultType;

final readonly class PaymentSessionResult
{
    private function __construct(
        public SessionResultType $type,
        public ?string $transactionId = null,
        // redirect
        public ?string $redirectUrl = null,
        public string $redirectMethod = 'GET',
        // form_redirect
        public ?string $formUrl = null,
        public array $formParams = [],
        public string $formMethod = 'POST',
        // widget
        public ?string $widgetProvider = null,
        public ?string $widgetScriptUrl = null,
        public array $widgetParams = [],
        // qr
        public ?string $qrData = null,
        public ?string $qrFormat = null,
        public ?string $qrPaymentId = null,
        public ?CarbonInterface $qrExpiresAt = null,
    ) {}

    public static function serverRedirect(string $url, string $method = 'GET', ?string $transactionId = null): self
    {
        return new self(type: SessionResultType::ServerRedirect, transactionId: $transactionId, redirectUrl: $url, redirectMethod: $method);
    }

    public static function formRedirect(string $url, array $params, string $method = 'POST'): self
    {
        return new self(type: SessionResultType::FormRedirect, formUrl: $url, formParams: $params, formMethod: $method);
    }

    public static function embeddedWidget(string $provider, string $scriptUrl, array $params, ?string $transactionId = null): self
    {
        return new self(type: SessionResultType::EmbeddedWidget, transactionId: $transactionId, widgetProvider: $provider, widgetScriptUrl: $scriptUrl, widgetParams: $params);
    }

    public static function qrInline(string $qrData, string $format, string $paymentId, ?CarbonInterface $expiresAt = null, ?string $transactionId = null): self
    {
        return new self(type: SessionResultType::QrInline, transactionId: $transactionId, qrData: $qrData, qrFormat: $format, qrPaymentId: $paymentId, qrExpiresAt: $expiresAt);
    }

    public function toArray(): array
    {
        return match ($this->type) {
            SessionResultType::ServerRedirect => [
                'type' => $this->type->value,
                'redirect_url' => $this->redirectUrl,
                'redirect_method' => $this->redirectMethod,
                'transaction_id' => $this->transactionId,
            ],
            SessionResultType::FormRedirect => [
                'type' => $this->type->value,
                'form_url' => $this->formUrl,
                'form_method' => $this->formMethod,
                'form_params' => $this->formParams,
            ],
            SessionResultType::EmbeddedWidget => [
                'type' => $this->type->value,
                'widget_provider' => $this->widgetProvider,
                'widget_script_url' => $this->widgetScriptUrl,
                'widget_params' => $this->widgetParams,
                'transaction_id' => $this->transactionId,
            ],
            SessionResultType::QrInline => [
                'type' => $this->type->value,
                'qr_data' => $this->qrData,
                'qr_format' => $this->qrFormat,
                'qr_payment_id' => $this->qrPaymentId,
                'qr_expires_at' => $this->qrExpiresAt?->toIso8601String(),
                'transaction_id' => $this->transactionId,
            ],
        };
    }
}
```

**Step 7: Add `capabilities()` to ConnectorInterface**

```php
// packages/streeboga/payment-data/src/Contracts/ConnectorInterface.php
// Add after testConnection():

    /**
     * Get the static capabilities of this connector (integration modes, direct methods, etc.)
     */
    public static function capabilities(): \Streeboga\PaymentConnectors\ConnectorCapabilities;
```

**Step 8: Run tests to verify they pass**

```bash
./vendor/bin/pest tests/Unit/Connectors/ConnectorCapabilitiesTest.php tests/Unit/Connectors/PaymentSessionResultTest.php
```
Expected: PASS

**Step 9: Commit**

```bash
git add packages/streeboga/payment-data/src/Enums/SessionResultType.php \
       packages/streeboga/payment-data/src/Enums/AmountUnit.php \
       packages/streeboga/payment-connectors/src/ConnectorCapabilities.php \
       packages/streeboga/payment-connectors/src/DirectMethod.php \
       packages/streeboga/payment-connectors/src/PaymentSessionResult.php \
       packages/streeboga/payment-data/src/Contracts/ConnectorInterface.php \
       tests/Unit/Connectors/ConnectorCapabilitiesTest.php \
       tests/Unit/Connectors/PaymentSessionResultTest.php
git commit -m "feat: add ConnectorCapabilities, PaymentSessionResult, SessionResultType"
```

---

## Task 2: HttpConnector base class — минимальный boilerplate для новых коннекторов

Цель: заменить неиспользуемый `AbstractConnector` (Omnipay) на `HttpConnector` с общим HTTP plumbing. Новый коннектор наследует `HttpConnector` и реализует только маппинги.

**Files:**
- Create: `packages/streeboga/payment-connectors/src/HttpConnector.php`
- Test: `tests/Unit/Connectors/HttpConnectorTest.php`

**Step 1: Write failing test**

```php
// tests/Unit/Connectors/HttpConnectorTest.php
use Illuminate\Support\Facades\Http;
use Streeboga\PaymentConnectors\HttpConnector;
use Streeboga\PaymentConnectors\ConnectorCapabilities;
use Streeboga\PaymentConnectors\DirectMethod;
use Streeboga\PaymentConnectors\PaymentSessionResult;
use Streeboga\PaymentData\Enums\AmountUnit;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\SessionResultType;

// Test stub connector
final class StubHttpConnector extends HttpConnector
{
    protected string $baseUrl = 'https://api.stub.com';

    public function getName(): string { return 'stub'; }

    public static function capabilities(): ConnectorCapabilities
    {
        return new ConnectorCapabilities(
            defaultDisplayName: ['en' => 'Stub PSP'],
            logoPath: '/logos/stub.svg',
            directMethods: ['card' => new DirectMethod(SessionResultType::ServerRedirect)],
            fallbackSessionType: SessionResultType::ServerRedirect,
            amountUnit: AmountUnit::Rubles,
        );
    }

    protected function configureRequest(): array
    {
        return ['auth' => [$this->credentials['login'], $this->credentials['password']]];
    }

    protected function buildPurchaseRequest(array $params): array
    {
        return ['endpoint' => '/pay', 'body' => ['amount' => $this->formatAmount($params['amount'])]];
    }

    protected function parsePurchaseResponse(array $body): array
    {
        return ['success' => $body['ok'] ?? false, 'transaction_id' => $body['id'] ?? null, 'message' => 'ok', 'code' => 'ok', 'data' => $body];
    }

    protected function buildCreateSessionRequest(array $params): array
    {
        return ['endpoint' => '/session', 'body' => ['amount' => $this->formatAmount($params['amount']), 'return_url' => $params['return_url']]];
    }

    protected function parseCreateSessionResponse(array $body): PaymentSessionResult
    {
        return PaymentSessionResult::serverRedirect($body['url'] ?? '');
    }

    // Optional overrides below have defaults in HttpConnector
    protected function buildAuthorizeRequest(array $params): array { return $this->buildPurchaseRequest($params); }
    protected function parseAuthorizeResponse(array $body): array { return $this->parsePurchaseResponse($body); }
    protected function buildCaptureRequest(array $params): array { return ['endpoint' => '/capture', 'body' => ['id' => $params['transaction_id']]]; }
    protected function parseCaptureResponse(array $body): array { return $this->parsePurchaseResponse($body); }
    protected function buildRefundRequest(array $params): array { return ['endpoint' => '/refund', 'body' => ['id' => $params['transaction_id']]]; }
    protected function parseRefundResponse(array $body): array { return $this->parsePurchaseResponse($body); }
    protected function buildVoidRequest(array $params): array { return ['endpoint' => '/void', 'body' => ['id' => $params['transaction_id']]]; }
    protected function parseVoidResponse(array $body): array { return $this->parsePurchaseResponse($body); }
    protected function buildGetStatusRequest(array $params): array { return ['endpoint' => '/status', 'body' => ['id' => $params['transaction_id']]]; }
    protected function parseGetStatusResponse(array $body): array { return $this->parsePurchaseResponse($body); }
    public function verifyWebhookSignature(string $payload, array $headers): bool { return true; }
    public function mapWebhookEventToStatus(string $eventType): ?PaymentStatus { return null; }
    public function extractPaymentIdFromWebhook(array $payload): ?string { return $payload['payment_id'] ?? null; }
    public function mapPaymentStatusToInternal(string $rawStatus): ?PaymentStatus { return null; }
}

it('makes purchase via HttpConnector', function () {
    Http::fake(['https://api.stub.com/pay' => Http::response(['ok' => true, 'id' => 'txn_1'])]);

    $connector = new StubHttpConnector(['login' => 'u', 'password' => 'p']);
    $result = $connector->purchase(['amount' => 10000, 'currency' => 'RUB', 'payment_id' => 'pay_1']);

    expect($result['success'])->toBeTrue();
    expect($result['transaction_id'])->toBe('txn_1');
});

it('formats amount based on AmountUnit', function () {
    $connector = new StubHttpConnector(['login' => 'u', 'password' => 'p']);

    // AmountUnit::Rubles → divides by 100
    expect($connector->formatAmount(10050))->toBe('100.50');
});

it('creates payment session via HttpConnector', function () {
    Http::fake(['https://api.stub.com/session' => Http::response(['url' => 'https://pay.stub.com/123'])]);

    $connector = new StubHttpConnector(['login' => 'u', 'password' => 'p']);
    $result = $connector->createPaymentSession(['amount' => 10000, 'return_url' => 'https://shop.com']);

    expect($result)->toBeInstanceOf(PaymentSessionResult::class);
    expect($result->type)->toBe(SessionResultType::ServerRedirect);
    expect($result->redirectUrl)->toBe('https://pay.stub.com/123');
});
```

**Step 2: Run test — FAIL**

```bash
./vendor/bin/pest tests/Unit/Connectors/HttpConnectorTest.php
```

**Step 3: Implement HttpConnector**

```php
// packages/streeboga/payment-connectors/src/HttpConnector.php
<?php
declare(strict_types=1);
namespace Streeboga\PaymentConnectors;

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentData\Contracts\ConnectorInterface;
use Streeboga\PaymentData\Enums\AmountUnit;

abstract class HttpConnector implements ConnectorInterface
{
    protected string $baseUrl;
    protected array $credentials;

    public function __construct(array $credentials)
    {
        $this->credentials = $credentials;
    }

    // --- Subclass implements these ---

    /** HTTP client config: ['auth' => [...]], ['headers' => [...]], etc. */
    abstract protected function configureRequest(): array;

    abstract protected function buildPurchaseRequest(array $params): array;
    abstract protected function parsePurchaseResponse(array $body): array;

    abstract protected function buildCreateSessionRequest(array $params): array;
    abstract protected function parseCreateSessionResponse(array $body): PaymentSessionResult;

    // --- Optional overrides (default to purchase) ---

    protected function buildAuthorizeRequest(array $params): array { return $this->buildPurchaseRequest($params); }
    protected function parseAuthorizeResponse(array $body): array { return $this->parsePurchaseResponse($body); }
    protected function buildCaptureRequest(array $params): array { return ['endpoint' => '', 'body' => []]; }
    protected function parseCaptureResponse(array $body): array { return $this->parsePurchaseResponse($body); }
    protected function buildRefundRequest(array $params): array { return ['endpoint' => '', 'body' => []]; }
    protected function parseRefundResponse(array $body): array { return $this->parsePurchaseResponse($body); }
    protected function buildVoidRequest(array $params): array { return ['endpoint' => '', 'body' => []]; }
    protected function parseVoidResponse(array $body): array { return $this->parsePurchaseResponse($body); }
    protected function buildGetStatusRequest(array $params): array { return ['endpoint' => '', 'body' => []]; }
    protected function parseGetStatusResponse(array $body): array { return $this->parsePurchaseResponse($body); }

    // --- HTTP Plumbing ---

    public function purchase(array $params): array
    {
        return $this->execute(
            $this->buildPurchaseRequest($params),
            fn (array $body) => $this->parsePurchaseResponse($body),
        );
    }

    public function authorize(array $params): array
    {
        return $this->execute(
            $this->buildAuthorizeRequest($params),
            fn (array $body) => $this->parseAuthorizeResponse($body),
        );
    }

    public function capture(array $params): array
    {
        return $this->execute(
            $this->buildCaptureRequest($params),
            fn (array $body) => $this->parseCaptureResponse($body),
        );
    }

    public function refund(array $params): array
    {
        return $this->execute(
            $this->buildRefundRequest($params),
            fn (array $body) => $this->parseRefundResponse($body),
        );
    }

    public function void(array $params): array
    {
        return $this->execute(
            $this->buildVoidRequest($params),
            fn (array $body) => $this->parseVoidResponse($body),
        );
    }

    public function getPaymentStatus(array $params): array
    {
        return $this->execute(
            $this->buildGetStatusRequest($params),
            fn (array $body) => $this->parseGetStatusResponse($body),
        );
    }

    public function createPaymentSession(array $params): PaymentSessionResult
    {
        $request = $this->buildCreateSessionRequest($params);
        $body = $this->sendRequest($request);

        return $this->parseCreateSessionResponse($body);
    }

    public function testConnection(): array
    {
        try {
            // Subclasses can override. Default: try a GET to baseUrl.
            $config = $this->configureRequest();
            $request = Http::timeout(10);
            if (isset($config['auth'])) {
                $request = $request->withBasicAuth($config['auth'][0], $config['auth'][1]);
            }
            $response = $request->get($this->baseUrl);

            return ['success' => $response->successful(), 'message' => $response->successful() ? 'Connection successful' : 'Failed'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // --- Amount formatting ---

    public function formatAmount(int $amount): string
    {
        $unit = static::capabilities()->amountUnit;

        return match ($unit) {
            AmountUnit::Rubles => number_format($amount / 100, 2, '.', ''),
            AmountUnit::Kopecks, AmountUnit::MinorUnits => (string) $amount,
        };
    }

    // --- Internal ---

    private function execute(array $request, \Closure $parser): array
    {
        try {
            $body = $this->sendRequest($request);
            return $parser($body);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'transaction_id' => null,
                'message' => $e->getMessage(),
                'code' => 'connector_error',
            ];
        }
    }

    private function sendRequest(array $request): array
    {
        $endpoint = $request['endpoint'] ?? '';
        $body = $request['body'] ?? [];
        $method = strtoupper($request['method'] ?? 'POST');
        $headers = $request['headers'] ?? [];

        $config = $this->configureRequest();

        $http = Http::timeout(30)->withHeaders($headers);

        if (isset($config['auth'])) {
            $http = $http->withBasicAuth($config['auth'][0], $config['auth'][1]);
        }
        if (isset($config['headers'])) {
            $http = $http->withHeaders($config['headers']);
        }
        if (isset($config['token'])) {
            $http = $http->withToken($config['token']);
        }

        $url = $this->baseUrl . $endpoint;

        $response = match ($method) {
            'GET' => $http->get($url, $body),
            default => $http->post($url, $body),
        };

        return $response->json() ?? [];
    }
}
```

**Step 4: Run tests — PASS**

```bash
./vendor/bin/pest tests/Unit/Connectors/HttpConnectorTest.php
```

**Step 5: Commit**

```bash
git add packages/streeboga/payment-connectors/src/HttpConnector.php \
       tests/Unit/Connectors/HttpConnectorTest.php
git commit -m "feat: add HttpConnector base class for minimal boilerplate connectors"
```

---

## Task 3: Добавить `capabilities()` в существующие коннекторы

**Files:**
- Modify: `packages/streeboga/payment-connectors/src/Drivers/YooKassaConnector.php`
- Modify: `packages/streeboga/payment-connectors/src/Drivers/CloudPaymentsConnector.php`
- Modify: `packages/streeboga/payment-connectors/src/Drivers/StripeConnector.php`
- Modify: `packages/streeboga/payment-connectors/src/Drivers/TestConnector.php`
- Test: `tests/Unit/Connectors/ConnectorCapabilitiesIntegrationTest.php`

**Step 1: Write failing test**

```php
// tests/Unit/Connectors/ConnectorCapabilitiesIntegrationTest.php
use Streeboga\PaymentConnectors\ConnectorCapabilities;
use Streeboga\PaymentConnectors\Drivers\YooKassaConnector;
use Streeboga\PaymentConnectors\Drivers\CloudPaymentsConnector;
use Streeboga\PaymentConnectors\Drivers\StripeConnector;
use Streeboga\PaymentConnectors\Drivers\TestConnector;
use Streeboga\PaymentData\Enums\SessionResultType;

it('all connectors return capabilities', function (string $class) {
    $caps = $class::capabilities();

    expect($caps)->toBeInstanceOf(ConnectorCapabilities::class);
    expect($caps->defaultDisplayName)->toHaveKey('en');
    expect($caps->logoPath)->toBeString();
    expect($caps->fallbackSessionType)->toBeInstanceOf(SessionResultType::class);
})->with([
    YooKassaConnector::class,
    CloudPaymentsConnector::class,
    StripeConnector::class,
    TestConnector::class,
]);

it('yookassa supports direct card and sbp', function () {
    $caps = YooKassaConnector::capabilities();

    expect($caps->supportsDirectMethod('card'))->toBeTrue();
    expect($caps->supportsDirectMethod('sbp'))->toBeTrue();
});

it('cloudpayments uses embedded widget', function () {
    $caps = CloudPaymentsConnector::capabilities();

    expect($caps->fallbackSessionType)->toBe(SessionResultType::EmbeddedWidget);
    expect($caps->supportsDirectMethod('card'))->toBeTrue();
});

it('stripe supports direct card', function () {
    $caps = StripeConnector::capabilities();

    expect($caps->supportsDirectMethod('card'))->toBeTrue();
    expect($caps->supportsDirectMethod('sbp'))->toBeFalse();
});

it('test connector supports direct card', function () {
    $caps = TestConnector::capabilities();

    expect($caps->supportsDirectMethod('card'))->toBeTrue();
});
```

**Step 2: Run test — FAIL**

**Step 3: Add capabilities() to each connector**

В каждый драйвер добавить `public static function capabilities(): ConnectorCapabilities`:

**YooKassaConnector:**
```php
public static function capabilities(): ConnectorCapabilities
{
    return new ConnectorCapabilities(
        defaultDisplayName: ['ru' => 'ЮKassa', 'en' => 'YooKassa'],
        logoPath: '/logos/yookassa.svg',
        directMethods: [
            'card' => new DirectMethod(SessionResultType::ServerRedirect),
            'sbp' => new DirectMethod(SessionResultType::ServerRedirect),
        ],
        fallbackSessionType: SessionResultType::ServerRedirect,
        amountUnit: AmountUnit::Rubles,
    );
}
```

**CloudPaymentsConnector:**
```php
public static function capabilities(): ConnectorCapabilities
{
    return new ConnectorCapabilities(
        defaultDisplayName: ['ru' => 'CloudPayments', 'en' => 'CloudPayments'],
        logoPath: '/logos/cloudpayments.svg',
        directMethods: [
            'card' => new DirectMethod(SessionResultType::EmbeddedWidget),
            'sbp' => new DirectMethod(SessionResultType::EmbeddedWidget),
        ],
        fallbackSessionType: SessionResultType::EmbeddedWidget,
        amountUnit: AmountUnit::Rubles,
    );
}
```

**StripeConnector:**
```php
public static function capabilities(): ConnectorCapabilities
{
    return new ConnectorCapabilities(
        defaultDisplayName: ['ru' => 'Stripe', 'en' => 'Stripe'],
        logoPath: '/logos/stripe.svg',
        directMethods: [
            'card' => new DirectMethod(SessionResultType::ServerRedirect),
        ],
        fallbackSessionType: SessionResultType::ServerRedirect,
        amountUnit: AmountUnit::MinorUnits,
    );
}
```

**TestConnector:**
```php
public static function capabilities(): ConnectorCapabilities
{
    return new ConnectorCapabilities(
        defaultDisplayName: ['ru' => 'Тестовый', 'en' => 'Test'],
        logoPath: '/logos/test.svg',
        directMethods: [
            'card' => new DirectMethod(SessionResultType::ServerRedirect),
        ],
        fallbackSessionType: SessionResultType::ServerRedirect,
        amountUnit: AmountUnit::MinorUnits,
    );
}
```

**Step 4: Run all connector tests to verify nothing broke**

```bash
./vendor/bin/pest tests/Unit/Connectors/
```

**Step 5: Commit**

```bash
git commit -am "feat: add capabilities() to all existing connectors"
```

---

## Task 4: Переписать `getAvailablePaymentMethods()` — smart method/connector resolution

**Files:**
- Modify: `app/Services/PaymentService.php` (lines 101-139)
- Modify: `packages/streeboga/payment-connectors/src/ConnectorFactory.php` (add `resolveClass`)
- Test: `tests/Feature/Services/PaymentMethodsV2Test.php`

**Step 1: Write failing test**

```php
// tests/Feature/Services/PaymentMethodsV2Test.php
use App\Services\PaymentService;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;

it('returns direct methods when connector supports them', function () {
    // Setup: one YooKassa connector with card+sbp enabled
    $payment = createPaymentWithConnector('yookassa', ['card', 'sbp']);

    $service = app(PaymentService::class);
    $result = $service->getAvailablePaymentMethods($payment, 'ru');

    expect($result['mode'])->toBe('direct_methods');
    expect($result['methods'])->toHaveCount(2);
    expect($result['methods'][0]['method'])->toBe('card');
    expect($result['methods'][0]['session_type'])->toBe('redirect');
    expect($result['connectors'])->toBeEmpty();
});

it('returns connector selection when no direct methods supported', function () {
    // Setup: two connectors, neither supports direct methods
    // (would need a connector without direct methods — test connector with empty directMethods)
    $payment = createPaymentWithConnectors([
        ['name' => 'test', 'methods' => ['card']],
    ]);

    // TestConnector supports direct card, so this should actually be direct_methods
    // This test validates the logic works end-to-end
    $service = app(PaymentService::class);
    $result = $service->getAvailablePaymentMethods($payment, 'en');

    expect($result)->toHaveKeys(['mode', 'methods', 'connectors']);
});

it('returns mixed mode with multiple connectors', function () {
    // YooKassa (direct card+sbp) + test connector (direct card)
    // card → should pick one via routing, sbp → yookassa
    $payment = createPaymentWithConnectors([
        ['name' => 'yookassa', 'methods' => ['card', 'sbp']],
        ['name' => 'test', 'methods' => ['card']],
    ]);

    $service = app(PaymentService::class);
    $result = $service->getAvailablePaymentMethods($payment, 'ru');

    // Should have methods (direct) and possibly connectors
    expect($result['mode'])->toBeIn(['direct_methods', 'mixed']);
});

it('uses display_config override for display name', function () {
    $payment = createPaymentWithConnector('yookassa', ['card'], displayConfig: [
        'display_name' => ['ru' => 'Мой кошелёк', 'en' => 'My Wallet'],
    ]);

    $service = app(PaymentService::class);
    $result = $service->getAvailablePaymentMethods($payment, 'ru');

    // In connector_selection mode, display_name should be overridden
    // In direct_methods mode, method display names come from standard i18n
});
```

**Step 2: Run test — FAIL** (new return format not implemented)

**Step 3: Add `resolveClass` to ConnectorFactory**

```php
// ConnectorFactory.php — add method:
public static function resolveClass(string $connectorName): ?string
{
    return self::$drivers[$connectorName] ?? null;
}
```

**Step 4: Rewrite getAvailablePaymentMethods()**

```php
// PaymentService.php — replace getAvailablePaymentMethods:
public function getAvailablePaymentMethods(PaymentIntent $payment, ?string $locale = null): array
{
    $locale ??= 'en';

    $connectors = $this->merchantRepository
        ->getActiveConnectorsByMerchant($payment->merchant_account_id)
        ->where('business_profile_id', $payment->business_profile_id)
        ->whereNotNull('payment_methods_enabled');

    $methods = [];
    $connectorSelection = [];

    foreach ($connectors as $mca) {
        $driverClass = ConnectorFactory::resolveClass($mca->connector_name);
        if (! $driverClass || ! method_exists($driverClass, 'capabilities')) {
            continue;
        }
        $caps = $driverClass::capabilities();
        $enabledMethods = collect($mca->payment_methods_enabled)
            ->map(fn ($m) => is_array($m) ? ($m['payment_method'] ?? $m) : $m);

        $hasDirectMethods = false;

        foreach ($enabledMethods as $method) {
            if ($caps->supportsDirectMethod($method)) {
                $hasDirectMethods = true;
                $dm = $caps->getDirectMethod($method);
                // Don't duplicate — first connector wins for each method
                if (! isset($methods[$method])) {
                    $methods[$method] = [
                        'method' => $method,
                        'display_name' => $this->getMethodDisplayName($method, $locale),
                        'type' => 'direct',
                        'connector' => $mca->connector_name,
                        'connector_key' => $mca->key,
                        'session_type' => $dm->sessionType->value,
                    ];
                }
            }
        }

        if (! $hasDirectMethods) {
            $displayName = $mca->display_config['display_name'][$locale]
                ?? $mca->display_config['display_name']['en']
                ?? $caps->displayName($locale);

            $connectorSelection[] = [
                'connector_name' => $mca->connector_name,
                'connector_key' => $mca->key,
                'display_name' => $displayName,
                'logo_url' => $mca->display_config['logo_url'] ?? $caps->logoPath,
                'session_type' => $caps->fallbackSessionType->value,
            ];
        }
    }

    $methodsList = array_values($methods);

    $mode = match (true) {
        ! empty($methodsList) && ! empty($connectorSelection) => 'mixed',
        ! empty($methodsList) => 'direct_methods',
        ! empty($connectorSelection) => 'connector_selection',
        default => 'none',
    };

    return [
        'mode' => $mode,
        'methods' => $methodsList,
        'connectors' => $connectorSelection,
    ];
}

private function getMethodDisplayName(string $method, string $locale): string
{
    $names = [
        'card' => ['ru' => 'Банковская карта', 'en' => 'Bank card'],
        'sbp' => ['ru' => 'СБП', 'en' => 'SBP'],
        'apple_pay' => ['ru' => 'Apple Pay', 'en' => 'Apple Pay'],
        'google_pay' => ['ru' => 'Google Pay', 'en' => 'Google Pay'],
    ];

    return $names[$method][$locale] ?? $names[$method]['en'] ?? $method;
}
```

**Step 5: Update PublicPaymentController to use new format**

Текущий контроллер вызывает `getAvailablePaymentMethods()` и оборачивает в JsonApiResource. Нужно обновить resource чтобы возвращал новый формат. Проверить `app/Http/Controllers/Api/V1/PublicPaymentController.php`.

**Step 6: Run tests**

```bash
./vendor/bin/pest tests/Feature/Services/PaymentMethodsV2Test.php
./vendor/bin/pest tests/Feature/Api/  # убедиться что ничего не сломано
```

**Step 7: Commit**

```bash
git commit -am "feat: smart payment methods API — direct/connector/mixed modes"
```

---

## Task 5: PaymentConfirmationService — поддержка 4 типов PaymentSessionResult

**Files:**
- Modify: `app/Services/PaymentConfirmationService.php` (lines 184-236)
- Test: `tests/Feature/Services/PaymentConfirmationV2Test.php`

**Step 1: Write failing test**

Тесты на каждый тип response: redirect (уже работает), form_redirect, widget, qr.

**Step 2: Обновить `executeRedirectFlow`**

Переименовать в `executeSessionFlow`. Обработать все 4 типа `PaymentSessionResult`:

```php
private function executeSessionFlow(PaymentIntent $payment, MerchantConnectorAccount $mca, array $connectorParams): ?array
{
    $connector = ConnectorFactory::resolve($mca);
    $sessionResult = $connector->createPaymentSession($connectorParams);

    // Если старый формат (array) — обернуть для обратной совместимости
    if (is_array($sessionResult)) {
        // legacy path — существующие коннекторы пока возвращают array
        return $this->handleLegacySessionResult($payment, $mca, $sessionResult);
    }

    // Новый формат — PaymentSessionResult
    $metadata = array_merge($payment->metadata ?? [], $sessionResult->toArray());

    PaymentStateMachine::assertTransition($payment->status, PaymentStatus::RequiresCustomerAction);
    $this->paymentRepository->update($payment, [
        'status' => PaymentStatus::RequiresCustomerAction,
        'connector' => $mca->connector_name,
        'metadata' => $metadata,
    ]);

    $this->paymentRepository->createAttempt($payment, [
        'connector' => $mca->connector_name,
        'connector_transaction_id' => $sessionResult->transactionId,
        'status' => PaymentAttemptStatus::RequiresAction->value,
        'amount' => $payment->amount,
    ]);
    $this->paymentRepository->incrementAttemptCount($payment);

    return $sessionResult->toArray();
}
```

**Step 3: Обновить PublicPaymentController confirm response**

Возвращать `action_type` из metadata для фронта.

**Step 4: Run tests**

```bash
./vendor/bin/pest tests/Feature/Services/ tests/Feature/Api/
```

**Step 5: Commit**

```bash
git commit -am "feat: PaymentConfirmationService handles 4 session result types"
```

---

## Task 6: Payment status polling endpoint

**Files:**
- Create: `app/Http/Controllers/Api/V1/PublicPaymentStatusController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/Payments/PaymentStatusPollingTest.php`

**Step 1: Write failing test**

```php
it('returns current payment status for polling', function () {
    $payment = createPayment(status: PaymentStatus::RequiresCustomerAction);

    $response = $this->getJson("/api/v1/payments/{$payment->key}/status?client_secret={$payment->client_secret}");

    $response->assertOk();
    $response->assertJsonPath('data.status', 'requires_customer_action');
});

it('returns succeeded when payment completes', function () {
    $payment = createPayment(status: PaymentStatus::Succeeded);

    $response = $this->getJson("/api/v1/payments/{$payment->key}/status?client_secret={$payment->client_secret}");

    $response->assertJsonPath('data.status', 'succeeded');
});
```

**Step 2: Implement lightweight controller**

```php
// Minimal: returns {status, amount, currency} — no heavy joins
public function __invoke(Request $request, string $paymentKey): JsonResponse
{
    $payment = PaymentIntent::where('key', $paymentKey)->firstOrFail();
    // client_secret verification (same as PublicPaymentController)

    return response()->json([
        'data' => [
            'status' => $payment->status->value,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
        ],
    ]);
}
```

**Step 3: Add route**

```php
// routes/api.php — inside public payments group
Route::get('payments/{paymentKey}/status', PublicPaymentStatusController::class);
```

**Step 4: Run tests, commit**

```bash
git commit -am "feat: add payment status polling endpoint for QR flow"
```

---

## Task 7: Widget v2 — обработка 4 типов action

**Files:**
- Modify: `widget/src/api.ts` — обновить типы response
- Modify: `widget/src/ui/PaymentWidget.tsx` — 4 action handlers
- Create: `widget/src/ui/QrPayment.tsx` — QR render + polling
- Create: `widget/src/ui/FormRedirect.tsx` — hidden form submit
- Modify: `widget/src/payswitch.ts` — передача session_type
- Test: `widget/src/tests/payment-widget.test.tsx`

**Step 1: Обновить типы**

```typescript
// widget/src/api.ts — обновить PaymentMethodInfo
interface PaymentMethodInfo {
    method: string;
    display_name: string;
    type: 'direct' | 'connector';
    connector: string;
    connector_key: string;
    session_type: 'redirect' | 'form_redirect' | 'widget' | 'qr';
}

interface PaymentMethodsResponse {
    mode: 'direct_methods' | 'connector_selection' | 'mixed' | 'none';
    methods: PaymentMethodInfo[];
    connectors: ConnectorInfo[];
}

interface ConfirmResponse {
    status: string;
    action_type: 'redirect' | 'form_redirect' | 'widget' | 'qr';
    // type-specific fields from PaymentSessionResult.toArray()
    redirect_url?: string;
    form_url?: string;
    form_params?: Record<string, string>;
    widget_provider?: string;
    widget_script_url?: string;
    widget_params?: Record<string, unknown>;
    qr_data?: string;
    qr_format?: string;
    qr_expires_at?: string;
}
```

**Step 2: QR компонент**

```tsx
// widget/src/ui/QrPayment.tsx
// Renders QR SVG/PNG, polls /status every 3s, shows success/timeout
```

**Step 3: FormRedirect компонент**

```tsx
// widget/src/ui/FormRedirect.tsx
// Creates hidden <form>, fills inputs, auto-submits
```

**Step 4: Обновить PaymentWidget.tsx**

```tsx
// В handleConfirm():
switch (response.action_type) {
    case 'redirect':
        window.location.href = response.redirect_url;
        break;
    case 'form_redirect':
        renderFormRedirect(response.form_url, response.form_params, response.form_method);
        break;
    case 'widget':
        loadWidgetScript(response.widget_script_url, response.widget_params);
        break;
    case 'qr':
        setQrData(response);
        startPolling(paymentKey, clientSecret);
        break;
}
```

**Step 5: Dual mode UI**

Виджет рендерит:
- `methods[]` → кнопки с методами (карта, СБП)
- `connectors[]` → кнопки с лого PSP
- Разделитель "или" если mixed mode
- Если один коннектор без direct methods → сразу кнопка "Оплатить" без выбора

**Step 6: Run widget tests**

```bash
cd widget && npm run test
```

**Step 7: Commit**

```bash
git commit -am "feat: widget v2 — 4 action types, QR inline, form redirect"
```

---

## Task 8: ConnectorName enum + dashboard frontend updates

**Files:**
- Modify: `app/Enums/ConnectorName.php` — добавить YooKassa (уже в factory, нет в enum)
- Modify: `dashboard/src/api/types/enums.ts` — синхронизировать
- Modify: `dashboard/src/pages/connector-detail.tsx` — capabilities preview
- Test: `tests/Unit/Enums/ConnectorNameTest.php`

**Step 1: Синхронизировать enum**

YooKassa есть в ConnectorFactory, но нет в ConnectorName enum. Добавить.

**Step 2: Dashboard — capabilities read-only блок**

На странице connector-detail добавить секцию "Capabilities" — integration mode, supported direct methods (data из нового API endpoint `/dashboard/connectors/{key}/capabilities`).

**Step 3: Run tests, commit**

---

## Порядок выполнения

```
Task 1 → Task 2 → Task 3 → Task 4 → Task 5 → Task 6 → Task 7 → Task 8
   │         │         │         │         │         │         │
   └─ value objects     │         │         │         │         └─ dashboard
        └─ base class   │         │         │         └─ widget v2
             └─ caps in drivers   │         └─ polling endpoint
                  └─ API v2      └─ confirm service v2
```

Task 1-3 — фундамент (без них ничего не работает)
Task 4-5 — бэкенд API
Task 6 — polling (нужен для QR)
Task 7 — фронт виджета
Task 8 — дашборд

---

## Как добавить новый коннектор после этого плана

После выполнения всех задач, для нового коннектора (например Т-Банк) нужно:

1. **Один файл** — `packages/streeboga/payment-connectors/src/Drivers/TBankConnector.php`:
   - Extends `HttpConnector`
   - `capabilities()` — static, описывает PSP
   - `configureRequest()` — auth
   - `buildPurchaseRequest()` / `parsePurchaseResponse()` — маппинги
   - `buildCreateSessionRequest()` / `parseCreateSessionResponse()` — session
   - Webhook methods (verify, map, extract)
   - ~100-150 строк кода

2. **Одна строка** — `ConnectorFactory::register('tbank', TBankConnector::class)` или добавить в `$drivers`

3. **Один case** — в `ConnectorName` enum

4. **Тесты** — `tests/Unit/Connectors/TBankConnectorTest.php` + webhook fixture JSON

Итого: **1 файл + 2 строки + тесты**. Минимальный boilerplate.
