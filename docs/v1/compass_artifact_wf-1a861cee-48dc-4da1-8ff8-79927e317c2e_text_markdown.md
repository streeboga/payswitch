# Техническая спецификация Hyperswitch-совместимого PHP-клона

Hyperswitch — open-source payment switch на Rust от Juspay, предоставляющий **единый Stripe-совместимый API** для маршрутизации платежей через **50+ процессоров** (Stripe, Adyen, Checkout.com, PayPal и др.). Ниже собрана вся необходимая информация для реализации минимального PHP/Laravel сервиса, эмулирующего ключевые API Hyperswitch с использованием OmniPay-PHP для реальных PSP-интеграций. Система работает с двумя базовыми URL: **sandbox.hyperswitch.io** (тестирование) и **api.hyperswitch.io** (продакшн), принимает и возвращает JSON, использует стандартные HTTP-коды.

---

## 1. Аутентификация: пять типов ключей и их применение

Hyperswitch использует **пять типов аутентификации**, каждый с чётко разделёнными правами доступа.

**API Key (Secret Key)** — основной ключ для server-to-server запросов. Передаётся в заголовке `api-key: snd_c69***`. Префикс зависит от окружения: `snd_` для sandbox, `prod_` для продакшна. Генерируется через Dashboard (Developers → API Keys → Create New Api Key) или через REST API. Никогда не передаётся клиенту.

**Admin API Key** — привилегированный ключ для системных операций: создание merchant account, connector account, organization. Задаётся в конфигурации при деплое сервера (в `docker_compose.toml` как `admin_api_key = "your_admin_key_here"` или в Helm `values.yaml`). Передаётся в заголовке `api-key`. Не генерируется через Dashboard — это конфигурационная константа.

**Publishable Key** — клиентский ключ с ограниченными правами. Безопасен для использования в браузере и мобильных приложениях. Формат: `pk_snd_3b3***` (префикс `pk_{environment}_{uuid}`). Генерируется автоматически при создании merchant account. Используется для инициализации Hyperswitch JS SDK.

**Ephemeral Key** — временный ключ для ограниченных операций (доступ к конкретному customer object, управление сохранёнными методами оплаты). Создаётся через `POST /ephemeral_keys` с `api-key` и customer_id в теле. Срок действия настраивается через `[eph_key] validity` в конфигурации.

**JWT Key** — Bearer-токен для аутентификации в Control Center. Формат: `Authorization: Bearer eyJhbG...`. Используется фронтендом дашборда; для PHP-клона реализация JWT опциональна.

Для минимального PHP-клона необходимо реализовать три типа: **Admin API Key** (tenant provisioning), **Secret API Key** (платёжные операции), **Publishable Key** (клиентские запросы). Merchant ID не передаётся явно в запросах — он **определяется из API key** автоматически.

---

## 2. Полная карта REST API эндпоинтов

### Payments (ядро системы)

| Метод | Путь | Описание |
|-------|------|----------|
| `POST` | `/payments` | Создание PaymentIntent |
| `POST` | `/payments/{id}/confirm` | Подтверждение платежа с данными метода оплаты |
| `POST` | `/payments/{id}/capture` | Захват авторизованных средств (manual capture) |
| `POST` | `/payments/{id}/cancel` | Отмена платежа |
| `GET` | `/payments/{id}` | Получение статуса платежа |
| `GET` | `/payments/list` | Список платежей |
| `POST` | `/payments/{id}` | Обновление платежа (Payments - Update) |
| `POST` | `/payments/session_tokens` | Создание session token (wallets) |

### Refunds

| Метод | Путь | Описание |
|-------|------|----------|
| `POST` | `/refunds` | Создание возврата |
| `GET` | `/refunds/{id}` | Получение возврата |
| `POST` | `/refunds/list` | Список возвратов |

### Customers

| Метод | Путь | Описание |
|-------|------|----------|
| `POST` | `/customers` | Создание/получение клиента |
| `GET` | `/customers/{id}` | Получение клиента |
| `POST` | `/customers/{id}` | Обновление клиента |
| `DELETE` | `/customers/{id}` | Удаление клиента |
| `POST` | `/customers/list` | Список клиентов |

### Account Management (требует Admin API Key)

| Метод | Путь | Описание |
|-------|------|----------|
| `POST` | `/organization` | Создание организации |
| `POST` | `/accounts` | Создание Merchant Account |
| `GET` | `/accounts/{id}` | Получение Merchant Account |
| `POST` | `/accounts/{id}` | Обновление Merchant Account |
| `POST` | `/profiles` | Создание Business Profile |
| `GET` | `/profiles/{id}` | Получение Business Profile |
| `POST` | `/profiles/{id}` | Обновление Business Profile |
| `POST` | `/api_keys/{merchant_id}` | Создание API Key |
| `GET` | `/api_keys/{merchant_id}` | Список API Keys |
| `POST` | `/api_keys/{merchant_id}/{key_id}` | Обновление API Key |
| `DELETE` | `/api_keys/{merchant_id}/{key_id}` | Отзыв API Key |
| `POST` | `/account/{merchant_id}/connectors` | Добавление Connector (PSP) |
| `GET` | `/account/{merchant_id}/connectors` | Список Connectors |
| `GET` | `/account/{merchant_id}/connectors/{id}` | Получение Connector |
| `POST` | `/account/{merchant_id}/connectors/{id}` | Обновление Connector |
| `DELETE` | `/account/{merchant_id}/connectors/{id}` | Удаление Connector |

### Другие

| Метод | Путь | Описание |
|-------|------|----------|
| `POST` | `/payment_methods` | Создание/сохранение метода оплаты |
| `GET` | `/payment_methods/{id}` | Получение метода оплаты |
| `POST` | `/mandates` | Работа с мандатами |
| `POST` | `/disputes` | Работа с диспутами |
| `POST` | `/routing` | Настройка маршрутизации |
| `POST` | `/ephemeral_keys` | Создание эфемерного ключа |

---

## 3. JSON-схемы ключевых эндпоинтов

### POST /payments — создание PaymentIntent

**Request** (минимальный):
```json
{
  "amount": 6540,
  "currency": "USD"
}
```

**Request** (расширенный):
```json
{
  "amount": 6540,
  "currency": "USD",
  "confirm": false,
  "capture_method": "automatic",
  "authentication_type": "no_three_ds",
  "customer_id": "cus_y3oqhf46pyzuxjbcn2giaqnb44",
  "description": "Payment for order #123",
  "return_url": "https://example.com/return",
  "setup_future_usage": "off_session",
  "payment_method": "card",
  "payment_method_type": "credit",
  "payment_method_data": {
    "card": {
      "card_number": "4242424242424242",
      "card_exp_month": "12",
      "card_exp_year": "2030",
      "card_cvc": "123",
      "card_holder_name": "John Doe"
    }
  },
  "billing": {
    "address": {
      "line1": "123 Main St",
      "city": "San Francisco",
      "state": "CA",
      "zip": "94111",
      "country": "US"
    }
  },
  "shipping": { ... },
  "metadata": { "order_id": "ORD-456" },
  "profile_id": "pro_pzzzzzzzzzzz",
  "payment_id": "pay_mbabizu24mvu3mela5njyhpit4",
  "session_expiry": 900,
  "connector": ["stripe", "adyen"],
  "routing": { "type": "single", "data": { "connector": "stripe" } },
  "payment_type": "normal",
  "mandate_id": null,
  "off_session": false,
  "statement_descriptor_name": "MYSHOP",
  "order_details": [{ "product_name": "Widget", "quantity": 1, "amount": 6540 }]
}
```

Ключевые поля request:
- **amount** (int64, required) — сумма в минимальных единицах валюты (центы для USD)
- **currency** (string, required) — ISO 4217 (USD, EUR, и т.д.)
- **confirm** (bool, default: false) — подтвердить сразу при создании
- **capture_method** (enum) — `automatic` | `manual` | `manual_multiple` | `scheduled`
- **authentication_type** (enum) — `three_ds` | `no_three_ds`
- **customer_id** (string, 1-64 chars) — ID клиента
- **payment_id** (string, 30 chars, optional) — идемпотентный ID платежа
- **profile_id** (string) — Business Profile
- **return_url** (string, max 2048) — URL для редиректа после 3DS/оплаты
- **setup_future_usage** (enum) — `off_session` | `on_session`
- **metadata** (object) — до 50 ключей, ключи до 40 символов, значения до 500
- **session_expiry** (int) — время жизни client_secret в секундах (по умолчанию 900)

**Response (200)**:
```json
{
  "payment_id": "pay_syxxxxxxxxxxxx",
  "merchant_id": "merchant_myyyyyyyyyyyy",
  "status": "requires_payment_method",
  "amount": 6540,
  "net_amount": 6540,
  "amount_capturable": 6540,
  "amount_received": null,
  "currency": "USD",
  "client_secret": "pay_syxxxxxxxxxxxx_secret_szzzzzzzzzzz",
  "created": "2023-10-26T10:00:00Z",
  "expires_on": "2023-10-26T10:15:00Z",
  "profile_id": "pro_pzzzzzzzzzzz",
  "processor_merchant_id": "merchant_myyyyyyyyyyyy",
  "payment_method": null,
  "payment_method_type": null,
  "connector": null,
  "attempt_count": 1,
  "customer_id": null,
  "description": null,
  "return_url": null,
  "metadata": {},
  "capture_method": "automatic",
  "authentication_type": "no_three_ds",
  "error_code": null,
  "error_message": null,
  "cancellation_reason": null,
  "next_action": null,
  "refunds": [],
  "mandate_id": null,
  "setup_future_usage": null
}
```

Ключевые поля response:
- **payment_id** (string, 30 chars, required) — формат `pay_*`
- **merchant_id** (string, required) — формат `merchant_*`
- **status** (enum, required) — см. state machine ниже
- **client_secret** (string) — формат `{payment_id}_secret_{random}`, используется на фронте для SDK
- **amount_capturable** (int64) — доступная сумма для capture
- **amount_received** (int64|null) — полученная сумма
- **next_action** (object|null) — данные для 3DS redirect или QR-кода
- **connector** (string|null) — PSP, обработавший платёж
- **attempt_count** (int) — количество попыток оплаты

### POST /payments/{id}/confirm

**Request**:
```json
{
  "payment_method": "card",
  "payment_method_type": "credit",
  "payment_method_data": {
    "card": {
      "card_number": "4111111111111111",
      "card_exp_month": "01",
      "card_exp_year": "2035",
      "card_holder_name": "Joseph Doe",
      "card_cvc": "100"
    }
  },
  "confirm": true,
  "browser_info": { "ip_address": "172.0.0.1" },
  "customer_acceptance": {
    "acceptance_type": "online",
    "accepted_at": "2024-01-01T00:00:00Z",
    "online": {
      "ip_address": "127.0.0.1",
      "user_agent": "Mozilla/5.0..."
    }
  }
}
```

Response аналогичен PaymentIntent response. При успешном `automatic` capture статус переходит в `processing` → `succeeded`. При `manual` capture — в `requires_capture`.

### POST /payments/{id}/capture

**Request**:
```json
{
  "amount_to_capture": 6540
}
```

Поле `amount_to_capture` — сумма для захвата в минимальных единицах. Должна быть ≤ авторизованной суммы.

### POST /refunds

**Request**:
```json
{
  "payment_id": "pay_syxxxxxxxxxxxx",
  "amount": 3000,
  "reason": "Customer returned the product",
  "metadata": {}
}
```

**Response**:
```json
{
  "refund_id": "ref_xxxxxxxxxxxxx",
  "payment_id": "pay_syxxxxxxxxxxxx",
  "amount": 3000,
  "currency": "USD",
  "status": "succeeded",
  "reason": "Customer returned the product",
  "error_code": null,
  "error_message": null,
  "metadata": {},
  "created_at": "2024-01-01T00:00:00Z",
  "updated_at": "2024-01-01T00:00:00Z",
  "connector": "stripe",
  "profile_id": "pro_xxx",
  "merchant_connector_id": "mca_xxx"
}
```

Статусы рефанда: `succeeded`, `failed`, `pending`, `manual_review`.

### POST /customers

**Request**:
```json
{
  "customer_id": "cus_optional_custom_id",
  "name": "John Doe",
  "email": "john@example.com",
  "phone": "+14155551234",
  "phone_country_code": "+1",
  "description": "VIP Customer",
  "metadata": { "tier": "premium" },
  "address": {
    "line1": "123 Main St",
    "city": "San Francisco",
    "state": "CA",
    "zip": "94111",
    "country": "US"
  }
}
```

**Response**:
```json
{
  "customer_id": "cus_y3oqhf46pyzuxjbcn2giaqnb44",
  "name": "John Doe",
  "email": "john@example.com",
  "phone": "+14155551234",
  "phone_country_code": "+1",
  "description": "VIP Customer",
  "metadata": { "tier": "premium" },
  "default_payment_method_id": null,
  "created_at": "2024-01-01T00:00:00Z"
}
```

Если `customer_id` не передан — генерируется автоматически (формат `cus_*`, 1-64 символа).

---

## 4. State machine платежа: полный жизненный цикл

Hyperswitch использует **две сущности**: `PaymentIntent` (общий статус) и `PaymentAttempt` (каждая попытка оплаты через конкретный коннектор). Один PaymentIntent может иметь несколько PaymentAttempt при ретраях.

**Основные статусы PaymentIntent**:

| Статус | Описание | Следующий шаг |
|--------|----------|---------------|
| `requires_payment_method` | Создан без метода оплаты | Обновить/подтвердить с методом |
| `requires_confirmation` | Метод прикреплён, ждёт confirm | `POST /payments/{id}/confirm` |
| `requires_customer_action` | 3DS/редирект/аутентификация | Пользователь выполняет действие |
| `requires_merchant_action` | Ожидание действия мерчанта (напр. ручная проверка фрода) | Мерчант принимает решение |
| `processing` | Обрабатывается процессором | Ожидание ответа PSP |
| `requires_capture` | Авторизован (manual capture) | `POST /payments/{id}/capture` |
| `succeeded` | Успешно завершён | Конец |
| `failed` | Ошибка оплаты | Возможен retry |
| `cancelled` | Отменён | Конец |
| `expired` | Истёк client_secret | Конец |
| `partially_captured` | Частично захвачен | Конец |
| `partially_captured_and_capturable` | Частично захвачен, остаток доступен | Capture остатка |

**Типичный flow создания платежа:**

1. **Create** → `POST /payments` с `amount`, `currency` → статус `requires_payment_method`
2. **Confirm** → `POST /payments/{id}/confirm` с данными карты → статус `processing` (automatic) или `requires_capture` (manual)
3. **Capture** (если manual) → `POST /payments/{id}/capture` → статус `succeeded`

**Ускоренный flow** (create + confirm в одном вызове): передать `confirm: true` и `payment_method_data` при создании.

**3DS flow**: после confirm, если требуется 3DS, статус переходит в `requires_customer_action`. Ответ содержит `next_action` с `redirect_to_url` — URL для перенаправления клиента. После аутентификации Hyperswitch SDK или redirect обратно на `return_url` автоматически обрабатывает результат.

**Client Secret** — строка формата `pay_XXXXX_secret_YYYYY`, возвращаемая при создании платежа. Передаётся на фронтенд для инициализации Hyperswitch JS SDK (`hyper.widgets({ clientSecret })`). Позволяет SDK безопасно работать с платежом без раскрытия secret key. Имеет срок жизни (по умолчанию **900 секунд** / 15 минут), настраиваемый через `session_expiry`.

---

## 5. Merchant provisioning и подключение коннекторов

Полный процесс онбординга merchant в self-deployed окружении выполняется через **Admin API Key** в следующем порядке:

**Шаг 1: Создание Organization** → `POST /organization` с Admin API Key. Организация — верхнеуровневая сущность, объединяющая merchant accounts.

**Шаг 2: Создание Merchant Account** → `POST /accounts` с Admin API Key. Возвращает `merchant_id` (формат `merchant_*`) и `publishable_key`.

**Шаг 3: Создание Business Profile** → `POST /profiles` с Admin API Key. Профиль определяет бизнес-юнит мерчанта, webhook URL, `payment_response_hash_key` для подписи webhook-ов. Каждый merchant может иметь несколько профилей. Возвращает `profile_id` (формат `pro_*`).

**Шаг 4: Генерация API Key** → `POST /api_keys/{merchant_id}` с Admin API Key. Возвращает secret key (формат `snd_*` или `prod_*`).

**Шаг 5: Подключение Connector (PSP)** → `POST /account/{merchant_id}/connectors` с Admin API Key.

Основные поля запроса для создания connector:
```json
{
  "connector_type": "fiz_operations",
  "connector_name": "stripe",
  "connector_account_details": {
    "auth_type": "HeaderKey",
    "api_key": "sk_test_XXXX"
  },
  "payment_methods_enabled": [
    {
      "payment_method": "card",
      "payment_method_types": [
        {
          "payment_method_type": "credit",
          "card_networks": ["Visa", "Mastercard"],
          "minimum_amount": 100,
          "maximum_amount": 10000000
        }
      ]
    }
  ],
  "metadata": {},
  "test_mode": true,
  "disabled": false,
  "business_country": "US",
  "business_label": "default",
  "profile_id": "pro_xxx"
}
```

Поле `connector_account_details` содержит PSP-специфичные credentials. Для **Stripe**: `{ "auth_type": "HeaderKey", "api_key": "sk_test_..." }`. Для **Adyen**: `{ "auth_type": "BodyKey", "api_key": "...", "key1": "merchant_account_name" }`. Тип `auth_type` варьируется: `HeaderKey`, `BodyKey`, `SignatureKey`, `MultiAuthKey` и другие.

Connector types: `fiz_operations` (платёжные операции), `payout_processor`, `fraud_check`, `accounting`, `tax`.

**Полный список поддерживаемых коннекторов (50+)**: `stripe`, `adyen`, `checkout`, `paypal`, `braintree`, `cybersource`, `bankofamerica`, `nmi`, `bluesnap`, `airwallex`, `worldpay`, `fiserv`, `globalpay`, `shift4`, `square`, `nuvei`, `rapyd`, `dlocal`, `klarna`, `mollie`, `multisafepay`, `trustpay`, `stax`, `forte`, `helcim`, `nexinets`, `payme`, `payone`, `paystack`, `payu`, `razorpay`, `bitpay`, `coinbase`, `opennode`, `zen`, `gocardless`, `wise`, `volt`, `truelayer`, `xendit`, `amazonpay`, `bambora`, `datatrans`, `noon`, `novalnet`, `placetopay`, `redsys`, `iatapay` и другие.

---

## 6. Webhooks: события, подписи и retry

Hyperswitch отправляет webhook-и как **HTTP POST с JSON payload** на URL, настроенный в Business Profile. Формат входящего webhook-а от процессоров: `{base_url}/webhooks/{merchant_id}/{merchant_connector_id}`.

### Типы событий

Полный перечень событий для outgoing webhooks мерчанту:

- **Платежи**: `payment_succeeded`, `payment_failed`, `payment_processing`, `payment_cancelled`, `payment_authorized`, `payment_captured`, `action_required`
- **Рефанды**: `refund_succeeded`, `refund_failed`
- **Диспуты**: `dispute_opened`, `dispute_expired`, `dispute_accepted`, `dispute_cancelled`, `dispute_challenged`, `dispute_won`, `dispute_lost`
- **Мандаты**: `mandate_active`, `mandate_revoked`

### Формат webhook payload

Payload содержит ключевые поля:
- `event_id` — уникальный ID события (для идемпотентности)
- `event_type` — тип события из списка выше
- `content` — объект ресурса (Payment/Refund/Dispute) в полном формате
- `updated` — timestamp последнего обновления ресурса

### Подпись webhook-ов

Используется **HMAC-SHA512**. Ключ подписи — `payment_response_hash_key`, задаваемый при создании Business Profile (если не задан — генерируется автоматически, **64 символа** с высокой энтропией).

Алгоритм генерации подписи:
1. Webhook payload кодируется как JSON-строка
2. Генерируется HMAC-SHA512 от payload с использованием `payment_response_hash_key`
3. Дайджест включается в заголовок **`x-webhook-signature-512`**

Для верификации: вычислить HMAC-SHA512 от тела запроса с тем же ключом и сравнить с заголовком `x-webhook-signature-512`. Альтернативный заголовок `x-webhook-signature-256` (HMAC-SHA256) доступен для совместимости.

### Retry policy

Для успешной доставки Hyperswitch ожидает **HTTP 2XX** от сервера мерчанта. При отсутствии 2XX — ретрай по расписанию в течение **24 часов**:

| Попытка | Интервал |
|---------|----------|
| 1-я | 1 минута |
| 2-я, 3-я | 5 минут |
| 4-я – 8-я | 10 минут |
| 9-я – 13-я | 1 час |
| 14-я – 16-я | 6 часов |

Обработка дубликатов: использовать `event_id` для идемпотентности. Обработка порядка: использовать поле `updated` (timestamp) для сравнения актуальности.

---

## 7. Redirect URL и подпись редиректа

После оплаты (3DS, wallet redirect) пользователь перенаправляется на `return_url` с query-параметрами:

```
https://example.com/return?status=succeeded
  &payment_intent_client_secret=pay_XXX_secret_YYY
  &amount=10000
  &manual_retry_allowed=false
  &signature=4fae0cfa775e...
  &signature_algorithm=HMAC-SHA512
```

Параметры: `status` (`succeeded` | `processing` | `failed`), `payment_intent_client_secret`, `manual_retry_allowed` (bool), `signature` (HMAC подпись, проверяемая через `payment_response_hash_key`), `signature_algorithm`.

---

## 8. Рекомендации для PHP/Laravel реализации

### Маппинг на OmniPay

Hyperswitch **wire-compatible со Stripe API** — это означает, что структура запросов/ответов близка к Stripe. Для PHP-клона:

- **PaymentIntent → OmniPay authorize/purchase**: `capture_method: automatic` маппится на `purchase()`, `capture_method: manual` — на `authorize()` + `capture()`
- **Refund → OmniPay refund**: прямой маппинг
- **Connector → OmniPay Gateway**: каждый connector в терминах Hyperswitch = конкретный OmniPay Gateway (omnipay-stripe, omnipay-adyen и т.д.)
- **connector_account_details → OmniPay credentials**: хранить зашифрованно в БД, передавать при инициализации gateway

### Структура БД (минимальная)

- **organizations** (id, name, metadata)
- **merchant_accounts** (id, org_id, merchant_id, publishable_key, metadata)
- **business_profiles** (id, merchant_id, profile_id, webhook_url, payment_response_hash_key)
- **api_keys** (id, merchant_id, key_hash, prefix, name, expires_at)
- **merchant_connector_accounts** (id, merchant_id, profile_id, connector_name, connector_type, connector_account_details_encrypted, payment_methods_enabled_json, test_mode, disabled)
- **payment_intents** (id, payment_id, merchant_id, profile_id, amount, currency, status, client_secret, capture_method, authentication_type, customer_id, return_url, metadata, connector, attempt_count, created, expires_on)
- **payment_attempts** (id, payment_id, attempt_id, connector, status, amount, error_code, error_message)
- **customers** (id, customer_id, merchant_id, name, email, phone, metadata)
- **refunds** (id, refund_id, payment_id, merchant_id, amount, currency, status, reason, connector, metadata)
- **webhook_events** (id, event_id, event_type, merchant_id, payload, delivered, retry_count, next_retry_at)

### Генерация ID

Формат ID следует паттернам Hyperswitch: `pay_` + 26 символов (payment_id), `cus_` + символы (customer_id), `ref_` + символы (refund_id), `pro_` + символы (profile_id), `mca_` + символы (merchant_connector_id). Client secret: `{payment_id}_secret_{random_string}`.

### Routing (минимальный)

Для MVP достаточно реализовать **single routing** (явное указание коннектора) и **priority list** (fallback на следующий коннектор при ошибке). Полный smart routing Hyperswitch (volume split, rule-based, auth-rate based) можно отложить.

---

## Заключение: что реализовать в первую очередь

Для рабочего MVP PHP-клона с Hyperswitch-совместимым API критичны **шесть компонентов**: (1) аутентификация по api-key с определением merchant_id, (2) полный payments lifecycle — create/confirm/capture/cancel/retrieve, (3) refunds CRUD, (4) customers CRUD, (5) merchant provisioning через admin API — accounts, profiles, connectors, API keys, (6) webhooks с HMAC-SHA512 подписью. State machine платежа с корректными переходами статусов — ключевой элемент: без него невозможен ни manual capture flow, ни 3DS-редиректы, ни retry-логика. OmniPay-PHP обеспечит реальные PSP-вызовы, но orchestration layer (routing, retry, status mapping) придётся писать самостоятельно, используя описанные выше статусы и переходы как blueprint.