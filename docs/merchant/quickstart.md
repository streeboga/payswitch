# Быстрый старт

Руководство по интеграции Payswitch для приёма платежей на вашем сайте.

## 1. Получите API-ключи

В личном кабинете Payswitch вам доступны два ключа:

| Ключ | Префикс | Назначение |
|------|---------|------------|
| Publishable key | `pk_...` | Используется на фронтенде (виджет). Безопасен для браузера. |
| Secret key | `sk_...` | Используется на бэкенде. **Никогда не передавайте его на клиент.** |

## 2. Создайте платёж (бэкенд)

Отправьте запрос с вашего сервера для создания платежа:

```http
POST /api/v1/payments
Authorization: Bearer sk_xxx
Content-Type: application/vnd.api+json
Accept: application/vnd.api+json
```

Тело запроса:

```json
{
  "data": {
    "type": "payments",
    "attributes": {
      "amount": 150000,
      "currency": "RUB",
      "description": "Заказ #1234",
      "return_url": "https://example.com/payment/result"
    }
  }
}
```

> **Важно:** сумма указывается в минимальных единицах валюты (копейках для RUB). 150000 = 1500.00 руб.

В ответе вы получите объект платежа в формате JSON:API, содержащий `client_secret` — он понадобится для инициализации виджета:

```json
{
  "data": {
    "type": "payments",
    "id": "pay_abc123",
    "attributes": {
      "status": "pending",
      "amount": 150000,
      "currency": "RUB",
      "client_secret": "pay_abc123_secret_xyz789",
      "..."
    }
  }
}
```

## 3. Подключите виджет (фронтенд)

Установите SDK:

```bash
npm install @payswitch/js
```

Добавьте контейнер на страницу:

```html
<div id="payment-widget"></div>
```

Инициализируйте виджет:

```javascript
import { loadPayswitch } from '@payswitch/js';

// Инициализация SDK с publishable key
const payswitch = await loadPayswitch('pk_your_publishable_key', {
  customBackendUrl: 'https://api.payswitch.example.com'
});

// Создание коллекции виджетов с client_secret от бэкенда
const widgets = payswitch.widgets({
  clientSecret: 'pay_abc123_secret_xyz789',
  locale: 'ru'
});

// Создание и монтирование платёжного виджета
const paymentWidget = widgets.create('payment');
paymentWidget.mount('#payment-widget');

// Подтверждение платежа по нажатию кнопки
document.getElementById('pay-button').addEventListener('click', async () => {
  const result = await payswitch.confirmPayment({
    widgets,
    confirmParams: {
      return_url: 'https://example.com/payment/result'
    },
    redirect: 'if_required'
  });

  if (result.error) {
    console.error(result.error.message);
  }
});
```

## 4. Обработайте вебхук (бэкенд)

Payswitch отправит POST-запрос на ваш `webhook_url` при изменении статуса платежа.

Запрос содержит заголовок `x-webhook-signature-512` для проверки подлинности.

```json
{
  "event_id": "evt_abc123",
  "event_type": "payment_succeeded",
  "content": {
    "payment_id": "pay_abc123",
    "status": "succeeded",
    "amount": 150000,
    "currency": "RUB"
  },
  "updated": "2026-03-24T12:00:00Z"
}
```

Проверьте подпись и обработайте событие:

```python
import hmac
import hashlib

def verify_signature(raw_body: bytes, signature: str, secret: str) -> bool:
    expected = hmac.new(
        secret.encode(), raw_body, hashlib.sha512
    ).hexdigest()
    return hmac.compare_digest(expected, signature)
```

Верните HTTP 200, чтобы подтвердить получение. Если ваш сервер вернёт другой код, Payswitch повторит доставку.

## Что дальше

- [Справочник API](api-reference.md) — полное описание эндпоинтов
- [Виджет SDK](widget.md) — все методы и события виджета
- [Вебхуки](webhooks.md) — типы событий, подпись, повторные доставки
