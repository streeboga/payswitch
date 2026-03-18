# Подключение нового коннектора

## Обзор архитектуры

```
ConnectorInterface          ← контракт (authorize, purchase, capture, refund, webhooks)
    ├── AbstractConnector   ← базовый класс для Omnipay-шлюзов (Stripe и др.)
    ├── CloudPaymentsConnector  ← прямой HTTP (без Omnipay)
    ├── YooKassaConnector       ← прямой HTTP (без Omnipay)
    └── TestConnector           ← мок для тестов

ConnectorFactory            ← резолвит коннектор по имени из MerchantConnectorAccount
```

Два варианта реализации:
- **Через Omnipay** — если для PSP есть пакет `omnipay-*`. Наследуешь `AbstractConnector`, реализуешь маппинг параметров.
- **Прямой HTTP** — если Omnipay-пакета нет. Реализуешь `ConnectorInterface` напрямую через `Http::`.

---

## Шаг 1. Драйвер коннектора

Создай файл в `packages/streeboga/payment-connectors/src/Drivers/`.

### Вариант A: через Omnipay

```php
<?php

declare(strict_types=1);

namespace Streeboga\PaymentConnectors\Drivers;

use Streeboga\PaymentConnectors\AbstractConnector;
use Streeboga\PaymentData\Enums\PaymentStatus;

final class TinkoffConnector extends AbstractConnector
{
    public function getName(): string
    {
        return 'tinkoff';
    }

    protected function getGatewayName(): string
    {
        return 'Tinkoff'; // имя Omnipay-шлюза
    }

    protected function configureGateway(array $credentials): void
    {
        $this->gateway->setTerminalKey($credentials['terminal_key'] ?? '');
        $this->gateway->setSecretKey($credentials['secret_key'] ?? '');
    }

    protected function mapPurchaseParams(array $params): array
    {
        return [
            'amount' => ($params['amount'] ?? 0) / 100, // копейки → рубли
            'currency' => $params['currency'] ?? 'RUB',
            'orderId' => $params['payment_id'] ?? null,
            'description' => $params['description'] ?? null,
        ];
    }

    protected function mapAuthorizeParams(array $params): array
    {
        return $this->mapPurchaseParams($params);
    }

    protected function mapCaptureParams(array $params): array
    {
        return [
            'amount' => ($params['amount'] ?? 0) / 100,
            'transactionReference' => $params['transaction_id'] ?? null,
        ];
    }

    protected function mapRefundParams(array $params): array
    {
        return [
            'amount' => ($params['amount'] ?? 0) / 100,
            'transactionReference' => $params['transaction_id'] ?? null,
        ];
    }

    // --- Webhooks ---

    public function verifyWebhookSignature(string $payload, array $headers): bool
    {
        // Реализуй проверку подписи по документации PSP
    }

    public function mapWebhookEventToStatus(string $eventType): ?PaymentStatus
    {
        return match ($eventType) {
            'CONFIRMED' => PaymentStatus::Succeeded,
            'REJECTED' => PaymentStatus::Failed,
            'REVERSED' => PaymentStatus::Cancelled,
            'AUTHORIZED' => PaymentStatus::RequiresCapture,
            default => null,
        };
    }

    public function extractPaymentIdFromWebhook(array $payload): ?string
    {
        return $payload['OrderId'] ?? null;
    }
}
```

### Вариант B: прямой HTTP

```php
<?php

declare(strict_types=1);

namespace Streeboga\PaymentConnectors\Drivers;

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentData\Contracts\ConnectorInterface;
use Streeboga\PaymentData\Enums\PaymentStatus;

final class TinkoffConnector implements ConnectorInterface
{
    private string $terminalKey;
    private string $secretKey;
    private array $credentials;
    private string $baseUrl = 'https://securepay.tinkoff.ru/v2';

    public function __construct(array $credentials)
    {
        $this->credentials = $credentials;
        $this->terminalKey = $credentials['terminal_key'] ?? '';
        $this->secretKey = $credentials['secret_key'] ?? '';
    }

    public function getName(): string
    {
        return 'tinkoff';
    }

    public function purchase(array $params): array
    {
        return $this->makeRequest('/Init', [
            'TerminalKey' => $this->terminalKey,
            'Amount' => $params['amount'] ?? 0, // Tinkoff принимает в копейках
            'OrderId' => $params['payment_id'] ?? uniqid(),
            'Description' => $params['description'] ?? '',
        ]);
    }

    public function authorize(array $params): array
    {
        // То же что purchase, но capture_method передаётся на уровне терминала
        return $this->purchase($params);
    }

    public function capture(array $params): array
    {
        return $this->makeRequest('/Confirm', [
            'TerminalKey' => $this->terminalKey,
            'PaymentId' => $params['transaction_id'] ?? '',
            'Amount' => $params['amount'] ?? 0,
        ]);
    }

    public function refund(array $params): array
    {
        return $this->makeRequest('/Cancel', [
            'TerminalKey' => $this->terminalKey,
            'PaymentId' => $params['transaction_id'] ?? '',
            'Amount' => $params['amount'] ?? 0,
        ]);
    }

    public function verifyWebhookSignature(string $payload, array $headers): bool
    {
        $data = json_decode($payload, true);
        $token = $data['Token'] ?? null;
        if (! $token) {
            return false;
        }
        unset($data['Token']);
        $data['Password'] = $this->secretKey;
        ksort($data);
        $expected = hash('sha256', implode('', $data));

        return hash_equals($expected, $token);
    }

    public function mapWebhookEventToStatus(string $eventType): ?PaymentStatus
    {
        return match ($eventType) {
            'CONFIRMED' => PaymentStatus::Succeeded,
            'REJECTED' => PaymentStatus::Failed,
            'REVERSED', 'REFUNDED' => PaymentStatus::Cancelled,
            'AUTHORIZED' => PaymentStatus::RequiresCapture,
            default => null,
        };
    }

    public function extractPaymentIdFromWebhook(array $payload): ?string
    {
        return $payload['OrderId'] ?? null;
    }

    private function makeRequest(string $endpoint, array $data): array
    {
        // Добавляем Token (подпись запроса)
        $data['Password'] = $this->secretKey;
        ksort($data);
        $data['Token'] = hash('sha256', implode('', $data));
        unset($data['Password']);

        try {
            $response = Http::timeout(30)->post($this->baseUrl . $endpoint, $data);
            $body = $response->json();

            $success = ($body['Success'] ?? false) === true;

            return [
                'success' => $success,
                'transaction_id' => $body['PaymentId'] ?? null,
                'message' => $body['Message'] ?? ($body['Details'] ?? 'Unknown'),
                'code' => $success ? 'ok' : ($body['ErrorCode'] ?? 'error'),
                'data' => $body,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'transaction_id' => null,
                'message' => $e->getMessage(),
                'code' => 'connector_error',
            ];
        }
    }
}
```

---

## Шаг 2. Регистрация в фабрике

Файл: `packages/streeboga/payment-connectors/src/ConnectorFactory.php`

```php
private static array $drivers = [
    'stripe' => Drivers\StripeConnector::class,
    'cloudpayments' => Drivers\CloudPaymentsConnector::class,
    'test' => Drivers\TestConnector::class,
    'yookassa' => Drivers\YooKassaConnector::class,
    'tinkoff' => Drivers\TinkoffConnector::class, // ← добавить
];
```

---

## Шаг 3. Unit-тесты коннектора

Файл: `tests/Unit/Connectors/TinkoffConnectorTest.php`

Обязательный набор тестов (по каждому методу интерфейса):

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentConnectors\Drivers\TinkoffConnector;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Tests\TestCase;

uses(TestCase::class);

function tinkoffConnector(): TinkoffConnector
{
    return new TinkoffConnector([
        'terminal_key' => 'TinkoffBankTest',
        'secret_key' => 'test_secret',
    ]);
}

// --- Базовое ---

test('getName returns tinkoff', function () {
    expect(tinkoffConnector()->getName())->toBe('tinkoff');
});

// --- purchase ---

test('purchase sends correct request and parses success', function () {
    Http::fake([
        'securepay.tinkoff.ru/v2/Init' => Http::response([
            'Success' => true,
            'PaymentId' => '12345678',
            'Status' => 'NEW',
        ]),
    ]);

    $result = tinkoffConnector()->purchase([
        'amount' => 5000,
        'currency' => 'RUB',
        'payment_id' => 'pay_01TEST',
    ]);

    expect($result['success'])->toBeTrue();
    expect($result['transaction_id'])->toBe('12345678');
    expect($result['code'])->toBe('ok');
});

test('purchase handles error response', function () {
    Http::fake([
        'securepay.tinkoff.ru/v2/Init' => Http::response([
            'Success' => false,
            'ErrorCode' => '99',
            'Message' => 'Недостаточно средств',
        ]),
    ]);

    $result = tinkoffConnector()->purchase([
        'amount' => 5000,
        'payment_id' => 'pay_02TEST',
    ]);

    expect($result['success'])->toBeFalse();
    expect($result['code'])->toBe('99');
});

test('purchase handles server error gracefully', function () {
    Http::fake([
        'securepay.tinkoff.ru/v2/Init' => Http::response(null, 500),
    ]);

    $result = tinkoffConnector()->purchase([
        'amount' => 5000,
        'payment_id' => 'pay_03TEST',
    ]);

    expect($result['success'])->toBeFalse();
    expect($result['code'])->toBe('connector_error');
});

// --- authorize ---

test('authorize sends request', function () {
    Http::fake([
        'securepay.tinkoff.ru/v2/Init' => Http::response([
            'Success' => true,
            'PaymentId' => '87654321',
        ]),
    ]);

    $result = tinkoffConnector()->authorize([
        'amount' => 10000,
        'payment_id' => 'pay_04TEST',
    ]);

    expect($result['success'])->toBeTrue();
});

// --- capture ---

test('capture sends correct request', function () {
    Http::fake([
        'securepay.tinkoff.ru/v2/Confirm' => Http::response([
            'Success' => true,
            'PaymentId' => '12345678',
            'Status' => 'CONFIRMED',
        ]),
    ]);

    $result = tinkoffConnector()->capture([
        'amount' => 5000,
        'transaction_id' => '12345678',
    ]);

    expect($result['success'])->toBeTrue();
});

// --- refund ---

test('refund sends correct request', function () {
    Http::fake([
        'securepay.tinkoff.ru/v2/Cancel' => Http::response([
            'Success' => true,
            'PaymentId' => '12345678',
            'Status' => 'REFUNDED',
        ]),
    ]);

    $result = tinkoffConnector()->refund([
        'amount' => 3000,
        'transaction_id' => '12345678',
    ]);

    expect($result['success'])->toBeTrue();
});

// --- Webhook signature ---

test('verifyWebhookSignature validates correct token', function () {
    // Собираем подпись как это делает Tinkoff
    $data = ['TerminalKey' => 'TinkoffBankTest', 'OrderId' => '123', 'Success' => true, 'Status' => 'CONFIRMED'];
    $dataForHash = $data;
    $dataForHash['Password'] = 'test_secret';
    ksort($dataForHash);
    $token = hash('sha256', implode('', $dataForHash));

    $data['Token'] = $token;

    expect(tinkoffConnector()->verifyWebhookSignature(json_encode($data), []))->toBeTrue();
});

test('verifyWebhookSignature rejects invalid token', function () {
    $data = ['TerminalKey' => 'TinkoffBankTest', 'Token' => 'invalid_token'];

    expect(tinkoffConnector()->verifyWebhookSignature(json_encode($data), []))->toBeFalse();
});

test('verifyWebhookSignature rejects missing token', function () {
    expect(tinkoffConnector()->verifyWebhookSignature('{"TerminalKey":"x"}', []))->toBeFalse();
});

// --- Webhook event mapping ---

test('mapWebhookEventToStatus maps correctly', function () {
    $c = tinkoffConnector();

    expect($c->mapWebhookEventToStatus('CONFIRMED'))->toBe(PaymentStatus::Succeeded);
    expect($c->mapWebhookEventToStatus('REJECTED'))->toBe(PaymentStatus::Failed);
    expect($c->mapWebhookEventToStatus('REVERSED'))->toBe(PaymentStatus::Cancelled);
    expect($c->mapWebhookEventToStatus('AUTHORIZED'))->toBe(PaymentStatus::RequiresCapture);
    expect($c->mapWebhookEventToStatus('UNKNOWN'))->toBeNull();
});

// --- Webhook payment ID ---

test('extractPaymentIdFromWebhook extracts OrderId', function () {
    $c = tinkoffConnector();

    expect($c->extractPaymentIdFromWebhook(['OrderId' => 'pay_01ABC']))->toBe('pay_01ABC');
    expect($c->extractPaymentIdFromWebhook([]))->toBeNull();
});
```

---

## Шаг 4. Webhook-фикстуры

Создай JSON-файлы реальных ответов PSP в `tests/Fixtures/Webhooks/`:

```
tests/Fixtures/Webhooks/
├── tinkoff_payment_confirmed.json
├── tinkoff_payment_rejected.json
└── tinkoff_payment_authorized.json
```

Пример `tinkoff_payment_confirmed.json`:
```json
{
    "TerminalKey": "TinkoffBankTest",
    "OrderId": "pay_01TESTWEBHOOK",
    "Success": true,
    "Status": "CONFIRMED",
    "PaymentId": 12345678,
    "ErrorCode": "0",
    "Amount": 5000,
    "CardId": 987654,
    "Pan": "430000******0777",
    "ExpDate": "1230",
    "Token": ""
}
```

Загружай в тестах через `ConnectorTestData::webhookFixture('tinkoff_payment_confirmed')`.

---

## Шаг 5. Feature-тест (webhook receiver)

Добавь тест в `tests/Feature/Api/Webhooks/WebhookFixtureTest.php`:

```php
test('tinkoff: CONFIRMED webhook updates payment status', function () {
    $mca = createMca('tinkoff', ['terminal_key' => 'TinkoffBankTest', 'secret_key' => 'sk']);
    $payment = createProcessingPayment('pay_tinkoff_01');

    $fixture = ConnectorTestData::webhookFixture('tinkoff_payment_confirmed');
    $fixture['OrderId'] = $payment->key;

    $this->postJson(
        "/api/v1/webhooks/{$this->merchant->key}/{$mca->key}",
        array_merge($fixture, ['type' => 'CONFIRMED'])
    )->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
});
```

---

## Шаг 6. Запуск и проверка

```bash
# Unit-тесты коннектора
./vendor/bin/pest tests/Unit/Connectors/TinkoffConnectorTest.php -v

# Webhook-тесты
./vendor/bin/pest tests/Feature/Api/Webhooks/WebhookFixtureTest.php -v

# Весь suite — убедиться что ничего не сломалось
./vendor/bin/pest

# Lint
./vendor/bin/pint
```

---

## Контракт ответа коннектора

Каждый метод (`purchase`, `authorize`, `capture`, `refund`) обязан возвращать:

```php
[
    'success' => bool,          // операция прошла
    'transaction_id' => ?string, // ID транзакции на стороне PSP
    'message' => string,         // человекочитаемое сообщение
    'code' => string,            // 'ok' | код ошибки PSP | 'connector_error'
    'data' => array,             // сырые данные от PSP (опционально)
]
```

Специальные коды:
- `'ok'` — успех
- `'requires_action'` — требуется 3DS (должен содержать `data.redirect_url`)
- `'connector_error'` — исключение при вызове PSP
- Любой другой — ошибка от PSP (card_declined, insufficient_funds и т.д.)

---

## Контракт вебхуков

| Метод | Назначение |
|-------|-----------|
| `verifyWebhookSignature(string $payload, array $headers): bool` | Проверка подписи. Если не проходит — 401. |
| `mapWebhookEventToStatus(string $eventType): ?PaymentStatus` | Маппинг события PSP на внутренний статус. `null` = игнорируем. |
| `extractPaymentIdFromWebhook(array $payload): ?string` | Извлечение нашего `payment_id` / `key` из payload PSP. |

---

## Чеклист перед мёрджем

- [ ] Драйвер реализует все 8 методов `ConnectorInterface`
- [ ] Зарегистрирован в `ConnectorFactory::$drivers`
- [ ] Unit-тесты: purchase, authorize, capture, refund, ошибки, server error
- [ ] Unit-тесты: webhook signature (valid + invalid + missing)
- [ ] Unit-тесты: event mapping (все статусы PSP + unknown → null)
- [ ] Unit-тесты: extractPaymentIdFromWebhook (found + missing)
- [ ] Webhook JSON-фикстуры в `tests/Fixtures/Webhooks/`
- [ ] Feature-тест: webhook receiver с фикстурами
- [ ] `./vendor/bin/pest` — 0 failures
- [ ] `./vendor/bin/pint` — pass
