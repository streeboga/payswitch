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
| `project_id`, `operation_id`, `order_id` | string | нет | Ваши идентификаторы проекта, операции и заказа, до 128 символов каждый. Payswitch их не толкует: хранит и возвращает в ответах и в `content` событий платежа и возврата. Не переданное — `null` |
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

Заголовок `Idempotency-Key` (до 255 символов, уникален в пределах платежа) делает
запрос повторяемым: повтор того же захвата с тем же ключом возвращает платёж как
есть с заголовком `Idempotent-Replayed: true` и второй раз не списывает. Тот же
ключ с другой суммой или с отменой — 422 `idempotency_key_reused`. Ключ
запоминается только у проведённого захвата: после 502 повтор уйдёт провайдеру снова.

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

`Idempotency-Key` работает так же, как у захвата: повтор отмены с тем же ключом
возвращает отменённый платёж с `Idempotent-Replayed: true`, а не ошибку перехода.

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

### Статус `processing` и `amount_unconfirmed`

Платёж становится `succeeded`, только когда провайдер назвал списанную сумму и она
равна выставленной; `amount_received` — эта сумма. Если провайдер сообщил об успехе
без суммы, платёж остаётся `processing` с `error_code: amount_unconfirmed` (событие
`payment_status_changed`) и оплаченным не считается: дождитесь `payment_succeeded`
или вызовите `POST /api/v1/payments/{key}/sync`. Названа другая сумма —
`requires_merchant_action` с `error_code: amount_mismatch`.

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
