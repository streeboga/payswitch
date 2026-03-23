# Вебхуки

Payswitch отправляет HTTP-уведомления на ваш сервер при изменении статуса платежей и возвратов.

## Настройка

Укажите `webhook_url` в настройках бизнес-профиля в личном кабинете Payswitch. URL должен быть доступен из интернета и принимать POST-запросы.

## Формат запроса

```
POST {ваш webhook_url}
Content-Type: application/json
x-webhook-signature-512: <подпись>
```

### Тело запроса

```json
{
  "event_id": "evt_abc123def456",
  "event_type": "payment_succeeded",
  "content": {
    "payment_id": "pay_abc123",
    "status": "succeeded",
    "amount": 150000,
    "currency": "RUB",
    "description": "Заказ #1234",
    "metadata": {
      "order_id": "ORD-5678"
    }
  },
  "updated": "2026-03-24T12:00:00Z"
}
```

## Типы событий

### Платежи

| Событие | Описание |
|---------|----------|
| `payment_succeeded` | Платёж успешно завершён (средства списаны) |
| `payment_failed` | Платёж отклонён |
| `payment_cancelled` | Платёж отменён |
| `payment_authorized` | Средства заблокированы (двухстадийная оплата) |
| `payment_captured` | Заблокированные средства списаны |

### Возвраты

| Событие | Описание |
|---------|----------|
| `refund_succeeded` | Возврат успешно выполнен |
| `refund_failed` | Возврат отклонён |

## Проверка подписи

Каждый вебхук подписывается с помощью HMAC-SHA512. Подпись передаётся в заголовке `x-webhook-signature-512`.

Для проверки вычислите HMAC-SHA512 от **сырого тела запроса** (raw body), используя ваш `payment_response_hash_key` в качестве секретного ключа.

### Псевдокод

```
expected_signature = HMAC-SHA512(raw_request_body, payment_response_hash_key)
is_valid = constant_time_compare(expected_signature, x-webhook-signature-512)
```

### Пример на PHP

```php
$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE_512'] ?? '';
$secret = config('services.payswitch.hash_key');

$expected = hash_hmac('sha512', $rawBody, $secret);

if (! hash_equals($expected, $signature)) {
    abort(403, 'Invalid signature');
}

$payload = json_decode($rawBody, true);
// Обработка события...
```

### Пример на Python

```python
import hmac
import hashlib
import json

def handle_webhook(request):
    raw_body = request.body
    signature = request.headers.get('x-webhook-signature-512', '')
    secret = PAYSWITCH_HASH_KEY.encode()

    expected = hmac.new(secret, raw_body, hashlib.sha512).hexdigest()

    if not hmac.compare_digest(expected, signature):
        return HttpResponse(status=403)

    payload = json.loads(raw_body)
    # Обработка события...
    return HttpResponse(status=200)
```

### Пример на Node.js

```javascript
const crypto = require('crypto');

function verifyWebhook(rawBody, signature, secret) {
  const expected = crypto
    .createHmac('sha512', secret)
    .update(rawBody)
    .digest('hex');

  return crypto.timingSafeEqual(
    Buffer.from(expected),
    Buffer.from(signature)
  );
}
```

> **Важно:** всегда используйте функции сравнения с постоянным временем (`hash_equals`, `hmac.compare_digest`, `timingSafeEqual`), чтобы защититься от атак по времени (timing attacks).

## Доставка и повторы

- Payswitch ожидает ответ **HTTP 200** для подтверждения получения вебхука.
- Любой другой HTTP-код (или таймаут) считается ошибкой доставки.
- При ошибке Payswitch повторяет доставку **до 16 раз** с экспоненциальной задержкой (exponential backoff).
- Интервалы между повторами увеличиваются: от нескольких секунд до нескольких часов.

## Идемпотентность

Используйте `event_id` для дедупликации событий. Один и тот же вебхук может быть доставлен повторно (например, при сетевой ошибке после обработки, но до отправки HTTP 200). Ваш обработчик должен корректно обрабатывать повторные вызовы с одинаковым `event_id`.

Рекомендуемый подход:

1. При получении вебхука проверьте, обрабатывался ли `event_id` ранее.
2. Если да — верните HTTP 200 без повторной обработки.
3. Если нет — обработайте событие и сохраните `event_id` как обработанный.

```php
// Пример дедупликации
if (ProcessedWebhook::where('event_id', $payload['event_id'])->exists()) {
    return response('', 200); // Уже обработан
}

DB::transaction(function () use ($payload) {
    ProcessedWebhook::create(['event_id' => $payload['event_id']]);
    // Обработка события...
});
```

## Рекомендации

- Обрабатывайте вебхуки **асинхронно** (через очередь). Верните HTTP 200 как можно быстрее.
- Не полагайтесь только на вебхуки — периодически сверяйте статусы платежей через API.
- Логируйте все полученные вебхуки для отладки.
- Настройте мониторинг ошибок для вашего webhook-эндпоинта.
