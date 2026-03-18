# Payswitch Connector SDK

## Overview

Connector — адаптер между Payswitch и платёжным провайдером (PSP). Каждый коннектор реализует `ConnectorInterface` и обрабатывает 4 операции + вебхуки.

## Quick Start

```bash
# 1. Скопировать шаблон
cp src/Drivers/ConnectorTemplate.php.stub src/Drivers/YourPspConnector.php

# 2. Переименовать класс, заполнить методы

# 3. Зарегистрировать в ConnectorFactory
ConnectorFactory::register('your_psp', Drivers\YourPspConnector::class);

# 4. Написать тесты
```

## Архитектура

```
Payment Request → PaymentService → RoutingService → ConnectorFactory → YourConnector → PSP API
                                                                    ← Response array
```

```
PSP Webhook → WebhookReceiverController → YourConnector::verifyWebhookSignature()
                                        → YourConnector::extractPaymentIdFromWebhook()
                                        → YourConnector::mapWebhookEventToStatus()
                                        → PaymentService (status update)
```

## Контракт: ConnectorInterface

```php
interface ConnectorInterface
{
    // Оплата с автоматическим списанием (capture_method = automatic)
    public function purchase(array $params): array;

    // Авторизация без списания (capture_method = manual)
    public function authorize(array $params): array;

    // Списание ранее авторизованной суммы
    public function capture(array $params): array;

    // Возврат средств
    public function refund(array $params): array;

    // Имя коннектора (уникальный идентификатор)
    public function getName(): string;

    // Проверка подписи вебхука
    public function verifyWebhookSignature(string $payload, array $headers): bool;

    // Маппинг типа события PSP → PaymentStatus
    public function mapWebhookEventToStatus(string $eventType): ?PaymentStatus;

    // Извлечение payment_id из вебхука
    public function extractPaymentIdFromWebhook(array $payload): ?string;
}
```

## Входные параметры

### purchase() / authorize()

```php
[
    'amount'              => 5000,                           // int, в минорных единицах (копейки/центы)
    'currency'            => 'USD',                          // ISO 4217
    'payment_method'      => 'card',                         // string
    'payment_method_data' => [                               // данные метода оплаты
        'card' => [
            'card_number'    => '4242424242424242',
            'card_exp_month' => '12',
            'card_exp_year'  => '2030',
            'card_cvc'       => '123',
        ],
    ],
    'token'               => null,                           // ?string, токен сохранённой карты
    'description'         => 'Order #123',                   // ?string
    'metadata'            => ['order_id' => 'abc'],          // array
    'payment_id'          => 'pay_01ABC...',                 // string, внутренний ID (передать PSP в metadata!)
]
```

### capture()

```php
[
    'amount'         => 5000,              // int, сумма к списанию (может быть < авторизованной)
    'transaction_id' => 'psp_txn_123',     // string, ID транзакции из ответа authorize()
]
```

### refund()

```php
[
    'amount'         => 3000,              // int, сумма возврата (частичный возврат допустим)
    'transaction_id' => 'psp_txn_123',     // string, ID транзакции из ответа purchase()/capture()
]
```

## Выходной формат (обязательный)

Все 4 операции ДОЛЖНЫ возвращать:

```php
[
    'success'        => bool,              // true — операция успешна
    'transaction_id' => ?string,           // ID транзакции PSP (сохраняется для capture/refund)
    'message'        => string,            // Человекочитаемое сообщение
    'code'           => string,            // Код результата (см. ниже)
    'data'           => array,             // (опционально) сырые данные PSP
]
```

### Коды результата

| code | Когда использовать |
|------|--------------------|
| `ok` | Операция успешна |
| `card_declined` | Карта отклонена |
| `insufficient_funds` | Недостаточно средств |
| `requires_action` | Требуется 3DS / аутентификация покупателя |
| `expired_card` | Истёк срок карты |
| `invalid_card` | Невалидные данные карты |
| `connector_error` | Ошибка подключения / таймаут / 5xx |
| `refund_failed` | Ошибка возврата |
| `payment_failed` | Общая ошибка оплаты |

## Вебхуки

### Endpoint

```
POST /api/v1/webhooks/{merchantKey}/{connectorKey}
```

Payswitch вызывает 3 метода коннектора последовательно:

### 1. verifyWebhookSignature(payload, headers)

```php
// payload — сырое тело запроса (string)
// headers — заголовки (ключи в lowercase)

// Типичная реализация HMAC-SHA256:
$expected = hash_hmac('sha256', $payload, $this->webhookSecret);
return hash_equals($expected, $headers['x-signature'] ?? '');
```

### 2. mapWebhookEventToStatus(eventType)

Маппинг PSP-специфичных типов → `PaymentStatus` enum:

```php
return match ($eventType) {
    'payment.succeeded'           => PaymentStatus::Succeeded,
    'payment.failed'              => PaymentStatus::Failed,
    'payment.canceled'            => PaymentStatus::Cancelled,
    'payment.waiting_for_capture' => PaymentStatus::RequiresCapture,
    default                       => null,  // null = игнорировать событие
};
```

### 3. extractPaymentIdFromWebhook(payload)

Извлечь `payment_id` (наш `pay_xxx`), который был передан в `metadata` при создании:

```php
return $payload['metadata']['payment_id']
    ?? $payload['object']['metadata']['payment_id']
    ?? null;
```

## PaymentStatus enum

```php
enum PaymentStatus: string
{
    case RequiresPaymentMethod = 'requires_payment_method';
    case RequiresConfirmation  = 'requires_confirmation';
    case RequiresCustomerAction = 'requires_customer_action';
    case RequiresMerchantAction = 'requires_merchant_action';
    case Processing            = 'processing';
    case RequiresCapture       = 'requires_capture';
    case Succeeded             = 'succeeded';
    case Failed                = 'failed';
    case Cancelled             = 'cancelled';
    case Expired               = 'expired';
    case PartiallyCaptured     = 'partially_captured';
    case PartiallyCapturedAndCapturable = 'partially_captured_and_capturable';
}
```

## Два паттерна реализации

### 1. Direct HTTP (рекомендуется)

Реализовать `ConnectorInterface` напрямую. Используй `Http::withToken()` / `Http::withBasicAuth()`.

Примеры: `CloudPaymentsConnector`, `ConnectorTemplate.php.stub`

### 2. OmniPay-based

Наследовать `AbstractConnector`. Нужно реализовать 6 abstract-методов:

```php
class YourConnector extends AbstractConnector
{
    protected function getGatewayName(): string;           // OmniPay gateway name
    protected function configureGateway(array $creds): void;
    protected function mapPurchaseParams(array $params): array;
    protected function mapAuthorizeParams(array $params): array;
    protected function mapCaptureParams(array $params): array;
    protected function mapRefundParams(array $params): array;

    // + getName(), verifyWebhookSignature(), mapWebhookEventToStatus(), extractPaymentIdFromWebhook()
}
```

Пример: `StripeConnector`

## Credentials (connector_account_details)

Хранятся зашифрованными в `MerchantConnectorAccount.connector_account_details`.
Передаются в конструктор коннектора как `array $credentials`.

Типичная структура:

```json
{
    "auth_type": "HeaderKey",
    "api_key": "sk_live_xxx",
    "secret_key": "secret_xxx",
    "webhook_secret": "whsec_xxx",
    "test_mode": false
}
```

## Регистрация коннектора

```php
// В ConnectorFactory::$drivers:
private static array $drivers = [
    'stripe'        => Drivers\StripeConnector::class,
    'cloudpayments' => Drivers\CloudPaymentsConnector::class,
    'your_psp'      => Drivers\YourPspConnector::class,  // ← добавить
];

// Или динамически:
ConnectorFactory::register('your_psp', Drivers\YourPspConnector::class);
```

## Суммы и валюты

Payswitch хранит суммы в **минорных единицах** (центы, копейки):
- `$50.00 USD` = `5000`
- `1000 RUB` = `100000`

Если PSP ожидает major units (рубли/доллары), делите на 100:
```php
$amount = $params['amount'] / 100;  // 5000 → 50.00
```

## Checklist нового коннектора

- [ ] Реализовать `ConnectorInterface` (или наследовать `AbstractConnector`)
- [ ] `getName()` возвращает уникальное имя
- [ ] `purchase()` — одношаговая оплата
- [ ] `authorize()` — авторизация без списания
- [ ] `capture()` — списание по transaction_id
- [ ] `refund()` — возврат по transaction_id
- [ ] Все методы возвращают стандартный `['success', 'transaction_id', 'message', 'code']`
- [ ] `payment_id` передаётся PSP в metadata
- [ ] `verifyWebhookSignature()` проверяет HMAC
- [ ] `mapWebhookEventToStatus()` покрывает succeeded/failed/cancelled
- [ ] `extractPaymentIdFromWebhook()` извлекает наш payment_id
- [ ] Зарегистрирован в `ConnectorFactory`
- [ ] Unit-тесты с мок HTTP
- [ ] Интеграционный тест с sandbox credentials
