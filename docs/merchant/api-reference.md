# Справочник HTTP API

## Аутентификация

Все запросы к API требуют секретного ключа (`sk_...`) в заголовке:

```
Authorization: Bearer sk_xxx
```

Все запросы и ответы используют формат JSON:API v1.1:

```
Content-Type: application/vnd.api+json
Accept: application/vnd.api+json
```

## Платежи

### Создание платежа

```
POST /api/v1/payments
```

#### Параметры

| Поле | Тип | Обязательное | Описание |
|------|-----|:------------:|----------|
| `amount` | integer | да | Сумма в минимальных единицах валюты (копейки). Минимум: 1 |
| `currency` | string | да | Код валюты ISO 4217, 3 символа (например, `RUB`, `USD`) |
| `capture_method` | string | нет | Метод списания: `automatic` (по умолчанию) или `manual` (двухстадийная оплата) |
| `description` | string | нет | Описание платежа. Максимум: 1000 символов |
| `return_url` | string | нет | URL для перенаправления после оплаты. Должен быть валидным URL |
| `metadata` | object | нет | Произвольные пары ключ-значение для хранения дополнительных данных |
| `session_expiry` | integer | нет | Время жизни сессии в секундах. Диапазон: 60–86400 (по умолчанию 3600) |

#### Пример запроса

```json
{
  "data": {
    "type": "payments",
    "attributes": {
      "amount": 500000,
      "currency": "RUB",
      "capture_method": "manual",
      "description": "Подписка на 1 месяц",
      "return_url": "https://example.com/result",
      "metadata": {
        "order_id": "ORD-5678",
        "user_id": "42"
      },
      "session_expiry": 7200
    }
  }
}
```

#### Пример ответа

```json
{
  "data": {
    "type": "payments",
    "id": "pay_abc123",
    "attributes": {
      "status": "pending",
      "amount": 500000,
      "currency": "RUB",
      "capture_method": "manual",
      "description": "Подписка на 1 месяц",
      "client_secret": "pay_abc123_secret_xyz789",
      "return_url": "https://example.com/result",
      "metadata": {
        "order_id": "ORD-5678",
        "user_id": "42"
      },
      "created_at": "2026-03-24T12:00:00Z",
      "updated_at": "2026-03-24T12:00:00Z"
    }
  }
}
```

### Подтверждение (capture) платежа

Применяется только для двухстадийных платежей (`capture_method: manual`), когда платёж находится в статусе `authorized`.

```
POST /api/v1/payments/{key}/capture
```

| Параметр | Описание |
|----------|----------|
| `key` | Идентификатор платежа (`pay_abc123`) |

#### Пример ответа

```json
{
  "data": {
    "type": "payments",
    "id": "pay_abc123",
    "attributes": {
      "status": "succeeded",
      "amount": 500000,
      "currency": "RUB",
      "captured_at": "2026-03-24T12:05:00Z"
    }
  }
}
```

### Отмена платежа

Отменяет платёж, который ещё не был списан (статус `pending` или `authorized`).

```
POST /api/v1/payments/{key}/cancel
```

| Параметр | Описание |
|----------|----------|
| `key` | Идентификатор платежа (`pay_abc123`) |

#### Пример ответа

```json
{
  "data": {
    "type": "payments",
    "id": "pay_abc123",
    "attributes": {
      "status": "cancelled",
      "amount": 500000,
      "currency": "RUB",
      "cancelled_at": "2026-03-24T12:03:00Z"
    }
  }
}
```

## Возвраты

### Создание возврата

```
POST /api/v1/refunds
```

#### Параметры

| Поле | Тип | Обязательное | Описание |
|------|-----|:------------:|----------|
| `payment_id` | string | да | Идентификатор платежа для возврата |
| `amount` | integer | нет | Сумма возврата в минимальных единицах валюты. Если не указана — полный возврат |

#### Пример запроса

```json
{
  "data": {
    "type": "refunds",
    "attributes": {
      "payment_id": "pay_abc123",
      "amount": 50000
    }
  }
}
```

#### Пример ответа

```json
{
  "data": {
    "type": "refunds",
    "id": "ref_def456",
    "attributes": {
      "status": "pending",
      "payment_id": "pay_abc123",
      "amount": 50000,
      "currency": "RUB",
      "created_at": "2026-03-24T12:10:00Z"
    }
  }
}
```

### Получение статуса возврата

```
GET /api/v1/refunds/{key}
```

| Параметр | Описание |
|----------|----------|
| `key` | Идентификатор возврата (`ref_def456`) |

#### Пример ответа

```json
{
  "data": {
    "type": "refunds",
    "id": "ref_def456",
    "attributes": {
      "status": "succeeded",
      "payment_id": "pay_abc123",
      "amount": 50000,
      "currency": "RUB",
      "created_at": "2026-03-24T12:10:00Z",
      "updated_at": "2026-03-24T12:10:05Z"
    }
  }
}
```

## Формат ответов

Все ответы возвращаются в формате [JSON:API v1.1](https://jsonapi.org/).

### Ошибки

При ошибке API возвращает массив `errors`:

```json
{
  "errors": [
    {
      "status": "422",
      "title": "Validation Error",
      "detail": "Поле amount обязательно для заполнения.",
      "source": {
        "pointer": "/data/attributes/amount"
      }
    }
  ]
}
```

### HTTP-коды ответов

| Код | Описание |
|-----|----------|
| 200 | Успешный запрос |
| 201 | Ресурс создан |
| 400 | Некорректный запрос |
| 401 | Неверный или отсутствующий ключ API |
| 404 | Ресурс не найден |
| 422 | Ошибка валидации |
| 500 | Внутренняя ошибка сервера |
