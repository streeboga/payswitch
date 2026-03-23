# Payswitch Connector SDK

Полный гайд по добавлению нового платёжного коннектора — от бэкенда до дашборда.

## Архитектура

```
Widget/API → PaymentService → RoutingService → ConnectorFactory → YourConnector → PSP API
                                                                ← PaymentSessionResult (4 типа)

PSP Webhook → WebhookReceiverController → YourConnector::verifyWebhookSignature()
                                        → YourConnector::extractPaymentIdFromWebhook()
                                        → YourConnector::mapWebhookEventToStatus()
                                        → PaymentService (status update)
```

### 4 типа интеграции (SessionResultType)

| Тип | Когда использовать | Пример PSP |
|-----|-------------------|------------|
| `ServerRedirect` | REST API создаёт платёж → URL от PSP → redirect | ЮKassa, Stripe, Сбер, Т-Банк, Точка |
| `FormRedirect` | URL формируется локально с подписью → redirect | Робокасса |
| `EmbeddedWidget` | REST API → token/params → JS SDK на странице | CloudPayments |
| `QrInline` | REST API → QR данные → показываем + polling | Т-Банк SBP, Сбер SBP |

## Контракт: ConnectorInterface

```php
interface ConnectorInterface
{
    // --- Capabilities (статический, без инстанциации) ---
    public static function capabilities(): ConnectorCapabilities;

    // --- Платёжные операции ---
    public function purchase(array $params): array;           // Оплата (capture_method=automatic)
    public function authorize(array $params): array;          // Авторизация (capture_method=manual)
    public function capture(array $params): array;            // Списание авторизованной суммы
    public function refund(array $params): array;             // Возврат
    public function void(array $params): array;               // Отмена авторизации

    // --- Сессия оплаты (redirect/widget/QR) ---
    public function createPaymentSession(array $params): PaymentSessionResult|array;

    // --- Вебхуки ---
    public function verifyWebhookSignature(string $payload, array $headers): bool;
    public function mapWebhookEventToStatus(string $eventType): ?PaymentStatus;
    public function extractPaymentIdFromWebhook(array $payload): ?string;

    // --- Статус и мониторинг ---
    public function getPaymentStatus(array $params): array;
    public function mapPaymentStatusToInternal(string $rawStatus): ?PaymentStatus;
    public function testConnection(): array;
    public function getName(): string;
}
```

## Чеклист: новый коннектор от А до Я

### Backend (4 файла)

#### 1. Драйвер: `packages/streeboga/payment-connectors/src/Drivers/YourPspConnector.php`

```php
<?php
declare(strict_types=1);
namespace Streeboga\PaymentConnectors\Drivers;

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentConnectors\ConnectorCapabilities;
use Streeboga\PaymentConnectors\DirectMethod;
use Streeboga\PaymentConnectors\PaymentSessionResult;
use Streeboga\PaymentData\Contracts\ConnectorInterface;
use Streeboga\PaymentData\Enums\AmountUnit;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\SessionResultType;

final class YourPspConnector implements ConnectorInterface
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct(array $credentials)
    {
        $this->apiKey = $credentials['api_key'] ?? '';
        $this->baseUrl = $credentials['base_url'] ?? 'https://api.yourpsp.com';
    }

    // 1. CAPABILITIES — описывает возможности PSP
    public static function capabilities(): ConnectorCapabilities
    {
        return new ConnectorCapabilities(
            defaultDisplayName: ['ru' => 'Ваш PSP', 'en' => 'Your PSP'],
            logoPath: '/logos/yourpsp.svg',
            directMethods: [
                'card' => new DirectMethod(SessionResultType::ServerRedirect),
                // 'sbp' => new DirectMethod(SessionResultType::QrInline), // если поддерживает
            ],
            fallbackSessionType: SessionResultType::ServerRedirect,
            amountUnit: AmountUnit::Rubles, // или Kopecks, MinorUnits
        );
    }

    public function getName(): string { return 'yourpsp'; }

    // 2. PAYMENT SESSION — создание платёжной сессии
    public function createPaymentSession(array $params): PaymentSessionResult
    {
        $response = Http::withToken($this->apiKey)
            ->post($this->baseUrl . '/payments', [
                'amount' => $this->formatAmount($params['amount'] ?? 0),
                'return_url' => $params['return_url'] ?? '',
                'metadata' => ['payment_id' => $params['payment_id'] ?? ''],
            ]);

        $body = $response->json();

        return PaymentSessionResult::serverRedirect($body['payment_url']);
        // Или: PaymentSessionResult::formRedirect($url, $formParams)
        // Или: PaymentSessionResult::embeddedWidget($provider, $scriptUrl, $params)
        // Или: PaymentSessionResult::qrInline($qrData, 'svg', $paymentId)
    }

    // 3. ПЛАТЁЖНЫЕ ОПЕРАЦИИ — стандартный формат ответа
    public function purchase(array $params): array
    {
        // Если PSP redirect-only:
        return ['success' => false, 'transaction_id' => null,
                'message' => 'Use createPaymentSession', 'code' => 'not_supported'];

        // Если PSP поддерживает прямую оплату:
        // $response = Http::withToken(...)->post('/charge', [...]);
        // return ['success' => true, 'transaction_id' => $body['id'],
        //         'message' => 'ok', 'code' => 'ok', 'data' => $body];
    }

    public function authorize(array $params): array { /* аналогично purchase */ }
    public function capture(array $params): array { /* POST /capture с transaction_id */ }
    public function refund(array $params): array { /* POST /refund с transaction_id + amount */ }
    public function void(array $params): array { /* POST /cancel с transaction_id */ }

    // 4. ВЕБХУКИ
    public function verifyWebhookSignature(string $payload, array $headers): bool
    {
        $expected = hash_hmac('sha256', $payload, $this->apiKey);
        return hash_equals($expected, $headers['x-signature'] ?? '');
    }

    public function mapWebhookEventToStatus(string $eventType): ?PaymentStatus
    {
        return match ($eventType) {
            'payment.succeeded' => PaymentStatus::Succeeded,
            'payment.failed' => PaymentStatus::Failed,
            'payment.refunded' => null, // handled separately
            default => null,
        };
    }

    public function extractPaymentIdFromWebhook(array $payload): ?string
    {
        return $payload['metadata']['payment_id'] ?? null;
    }

    // 5. СТАТУС
    public function getPaymentStatus(array $params): array
    {
        $response = Http::withToken($this->apiKey)
            ->get($this->baseUrl . '/payments/' . ($params['transaction_id'] ?? ''));
        $body = $response->json();
        return ['success' => true, 'transaction_id' => $body['id'] ?? null,
                'message' => 'ok', 'code' => 'ok', 'data' => $body];
    }

    public function mapPaymentStatusToInternal(string $rawStatus): ?PaymentStatus
    {
        return match ($rawStatus) {
            'completed' => PaymentStatus::Succeeded,
            'declined' => PaymentStatus::Failed,
            'authorized' => PaymentStatus::RequiresCapture,
            default => null,
        };
    }

    public function testConnection(): array
    {
        try {
            $response = Http::withToken($this->apiKey)->get($this->baseUrl . '/health');
            return ['success' => $response->successful(), 'message' => 'OK'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function formatAmount(int $amount): string
    {
        return match (static::capabilities()->amountUnit) {
            AmountUnit::Rubles => number_format($amount / 100, 2, '.', ''),
            default => (string) $amount,
        };
    }
}
```

#### 2. Конфиг: `config/payswitch.php`

```php
'connectors' => [
    // ... existing
    'yourpsp' => \Streeboga\PaymentConnectors\Drivers\YourPspConnector::class,
],
```

#### 3. Enum: `app/Enums/ConnectorName.php`

```php
case YourPsp = 'yourpsp';

// + getLabel(), getColor(), getIcon() для нового case
```

#### 4. Тесты: `tests/Unit/Connectors/YourPspConnectorTest.php`

Что тестировать:
- `capabilities()` возвращает правильные значения
- `createPaymentSession()` делает правильный HTTP запрос и парсит ответ
- `purchase/authorize/capture/refund/void` — правильные эндпоинты и параметры
- `verifyWebhookSignature()` — валидный и невалидный
- `extractPaymentIdFromWebhook()` — извлекает payment_id
- `mapPaymentStatusToInternal()` — все статусы
- `formatAmount()` — правильная конвертация
- Error handling — connector_error при exception

Плюс: `tests/Fixtures/Webhooks/yourpsp_payment_succeeded.json`

### Frontend (9 файлов)

#### 5. Тип: `dashboard/src/api/types/enums.ts`
```typescript
export type ConnectorName = '...' | 'yourpsp'
```

#### 6. Список коннекторов: `dashboard/src/pages/connectors.tsx`
Добавить в `CONNECTOR_ICONS` и `CONNECTOR_LABELS`

#### 7. Детали коннектора: `dashboard/src/pages/connector-detail.tsx`
Добавить в `CONNECTOR_ICONS`, `CONNECTOR_LABELS` и `CREDENTIAL_FIELDS`:
```typescript
yourpsp: [
  { key: 'api_key', label: 'API Key', placeholder: 'pk_live_...' },
  { key: 'api_secret', label: 'API Secret', type: 'password' },
],
```

#### 8. Визард: `dashboard/src/components/connectors/connect-wizard.tsx`
Добавить в `CONNECTOR_TYPES` и `CREDENTIAL_FIELDS`

#### 9-11. Роутинг формы:
- `dashboard/src/components/routing/priority-form.tsx` → `AVAILABLE_CONNECTORS`
- `dashboard/src/components/routing/volume-split-form.tsx` → `CONNECTOR_OPTIONS`
- `dashboard/src/components/routing/rule-based-form.tsx` → `CONNECTOR_OPTIONS`

#### 12-13. Переводы:
- `dashboard/src/locales/en.json` — label, setupInstructions, wizardDesc
- `dashboard/src/locales/ru.json` — то же на русском

## Стандартный формат ответа (все операции)

```php
[
    'success'        => bool,       // true = операция успешна
    'transaction_id' => ?string,    // ID транзакции PSP (для capture/refund)
    'message'        => string,     // Человекочитаемое сообщение
    'code'           => string,     // ok, card_declined, insufficient_funds, requires_action,
                                    // expired_card, invalid_card, connector_error,
                                    // refund_failed, payment_failed, not_supported
    'data'           => array,      // (опционально) сырые данные PSP
]
```

## PaymentSessionResult — 4 типа

```php
// Redirect на hosted page PSP
PaymentSessionResult::serverRedirect($url, $method = 'GET', $transactionId = null)

// Form POST с подписью (Робокасса)
PaymentSessionResult::formRedirect($url, $params, $method = 'POST')

// JS widget PSP (CloudPayments)
PaymentSessionResult::embeddedWidget($provider, $scriptUrl, $params, $transactionId = null)

// QR код inline + polling (SBP)
PaymentSessionResult::qrInline($qrData, $format, $paymentId, $expiresAt = null, $transactionId = null)
```

## ConnectorCapabilities

```php
new ConnectorCapabilities(
    defaultDisplayName: ['ru' => '...', 'en' => '...'],  // Имя PSP
    logoPath: '/logos/yourpsp.svg',                       // Путь к логотипу
    directMethods: [                                       // Методы с прямым выбором
        'card' => new DirectMethod(SessionResultType::ServerRedirect),
        'sbp'  => new DirectMethod(SessionResultType::QrInline),
    ],
    fallbackSessionType: SessionResultType::ServerRedirect, // Режим по умолчанию
    amountUnit: AmountUnit::Rubles,                        // Rubles (/100), Kopecks (as-is), MinorUnits (as-is)
    sbpMethod: new DirectMethod(SessionResultType::QrInline), // Отдельный SBP (опционально)
)
```

## Суммы

Payswitch хранит суммы в **минорных единицах** (копейки/центы): `5000` = 50.00 RUB.

| AmountUnit | Что делать | Пример |
|------------|-----------|--------|
| `Rubles` | `$amount / 100` → `"50.00"` | ЮKassa, CloudPayments, Робокасса, Точка |
| `Kopecks` | `$amount` as-is → `"5000"` | Сбер, Альфа, Т-Банк |
| `MinorUnits` | `$amount` as-is → `5000` | Stripe |

## Credentials

Хранятся зашифрованно в `MerchantConnectorAccount.connector_account_details`.
Передаются в `__construct(array $credentials)`.

Примеры для разных PSP:
```json
// Stripe: {"api_key": "sk_live_xxx"}
// Сбер:   {"base_url": "https://securepayments.sberbank.ru/payment/rest", "username": "...", "password": "..."}
// Т-Банк: {"terminal_key": "xxx", "password": "yyy"}
// Робокасса: {"login": "shop", "password1": "xxx", "password2": "yyy"}
// Точка:  {"token": "jwt_token", "customer_code": "123456789"}
```

## Webhook endpoint

```
POST /api/v1/webhooks/{merchantKey}/{connectorKey}
```

Flow: `verifyWebhookSignature()` → `extractPaymentIdFromWebhook()` → `mapWebhookEventToStatus()` → status update.

## Итого: новый коннектор = 4 файла backend + 9 файлов frontend

| # | Файл | Что |
|---|------|-----|
| 1 | `src/Drivers/YourPspConnector.php` | Драйвер (~150-400 строк) |
| 2 | `config/payswitch.php` | +1 строка |
| 3 | `app/Enums/ConnectorName.php` | +1 case + label/color/icon |
| 4 | `tests/Unit/Connectors/YourPspConnectorTest.php` | Тесты (~20-30 тестов) |
| 5 | `dashboard/src/api/types/enums.ts` | +1 в union type |
| 6 | `dashboard/src/pages/connectors.tsx` | +icon +label |
| 7 | `dashboard/src/pages/connector-detail.tsx` | +icon +label +credential fields |
| 8 | `dashboard/src/components/connectors/connect-wizard.tsx` | +карточка +credentials |
| 9 | `dashboard/src/components/routing/priority-form.tsx` | +entry |
| 10 | `dashboard/src/components/routing/volume-split-form.tsx` | +entry |
| 11 | `dashboard/src/components/routing/rule-based-form.tsx` | +entry |
| 12 | `dashboard/src/locales/en.json` | +label +setup +desc |
| 13 | `dashboard/src/locales/ru.json` | +label +setup +desc |
