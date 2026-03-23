# Widget V2 — Connector Config + Smart Payment Method Logic

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Переделать логику виджета: вместо плоского списка методов (card, sbp, apple_pay) — умный выбор на основе возможностей PSP (hardcoded capabilities) и конфигурации мерчанта.

**Architecture:** PSP capabilities в коде драйвера (hardcoded). Мерчант настраивает только credentials, enabled methods, display name и logo. Виджет получает от API готовый набор вариантов оплаты.

---

## Проблема текущей реализации

Сейчас виджет показывает `["card", "sbp", "apple_pay", "google_pay", "bank_transfer"]` — плоский список из `payment_methods_enabled`. Это неправильно:

1. Не все PSP поддерживают прямой redirect на конкретный метод (Т-Банк, Сбер, Альфа — нельзя)
2. CloudPayments работает через виджет (JS popup), не через redirect
3. Робокасса не имеет REST API — только form redirect с подписью
4. SBP у Т-Банка, Сбера, Альфы — отдельный flow с QR кодом inline
5. Сбер и Альфа — один и тот же gateway (RBS), можно один коннектор

---

## Исследование PSP (7 провайдеров)

### Сводная матрица возможностей

| PSP | Создание платежа | Embedded widget | Direct method select | SBP QR inline | Сумма в | Webhook верификация |
|-----|-----------------|-----------------|---------------------|---------------|---------|---------------------|
| **ЮKassa** | REST `POST /v3/payments` | ✅ `type=embedded` → `YooMoneyCheckoutWidget` | ✅ `payment_method_data.type` | ✅ type=sbp → QR page | рублях | IP whitelist |
| **CloudPayments** | REST API | ✅ JS popup (основной режим) | ✅ `restrictedPaymentMethods` | ✅ через виджет | рублях | HMAC подпись |
| **Робокасса** | ❌ Form redirect (нет REST) | ✅ iframe `Robokassa.Render()` / modal | ⚠️ `IncCurrLabel` (soft, юзер может переключить) | ❌ только redirect | рублях | MD5 подпись (Password#2) |
| **Т-Банк** | REST `POST /v2/Init` | ⚠️ overlay `tinkoff_v2.js` (не inline) | ❌ метод на hosted page | ✅ `GetQr(DataType=IMAGE)` → SVG | **копейках** | SHA-256 Token |
| **Сбер** | REST `register.do` | ❌ нет | ⚠️ `allowedPaymentWays` (фильтр, не выбор) | ✅ `getSbpDynamicQr.do` → payload | **копейках** | Polling (callbacks ненадёжны) |
| **Альфа** | REST `register.do` (= Сбер, RBS gateway) | ❌ нет | ❌ | ✅ `sbp/c2b/qr/dynamic/get.do` → base64 PNG | **копейках** | HMAC (callbacks ненадёжны) |
| **Точка** | REST `POST acquiring/v1.0/payments` | ❌ нет | ✅ `paymentMode` (card/sbp/tinkoff/dolyame) | ✅ Отдельный SBP QR API | рублях | JWT (RS256) |

### Детали по каждому PSP

#### ЮKassa
- **Два режима:** `confirmation.type=redirect` (redirect на hosted page) или `confirmation.type=embedded` (JS widget на странице)
- **Direct methods:** `payment_method_data.type` = `bank_card`, `sbp`, `yoo_money`, `sberbank`, `tinkoff_bank`, `installments`
- **Embedded widget:** создаёт платёж с `type=embedded`, получает `confirmation_token`, передаёт в `YooMoneyCheckoutWidget` JS SDK. Виджет сам показывает все методы — **конфликтует с нашим виджетом**
- **Рекомендация:** использовать `redirect` + `payment_method_data.type` для direct methods. Embedded widget не использовать (дублирует наш UI)

#### CloudPayments
- **Основной режим:** JS popup widget. Collect card data через их виджет
- **Direct methods:** через `restrictedPaymentMethods` — отключаем ненужные методы
- **SBP:** через виджет с restricted methods (оставляем только QR)

#### Робокасса
- **Нет REST API.** URL формируется локально: `MD5(Login:Sum:InvId:Password#1:Shp_*)` → redirect на `auth.robokassa.ru`
- **iframe/modal:** `Robokassa.Render()` (inline) или `Robokassa.StartPayment()` (modal popup)
- **Direct methods:** `IncCurrLabel=BankCardPSR` / `SBP` — pre-select, но юзер может переключить
- **Stricter filter:** в iframe режиме `Settings.PaymentMethods: ['BankCard', 'SBP']` — ограничивает видимые методы
- **Два пароля:** Password#1 (инициация + SuccessURL), Password#2 (ResultURL webhook)

#### Т-Банк (Tinkoff)
- **REST API:** `POST /v2/Init` → `PaymentURL` для redirect
- **Нельзя указать метод** при Init. Пользователь выбирает на hosted page
- **SBP inline:** `Init` → `GetQr(PaymentId, DataType=IMAGE)` → SVG QR код. **Polling** через `GetState`
- **T-Pay:** отдельные эндпоинты для deeplink/QR в приложение Т-Банка
- **Amount в копейках** (×100)

#### Сбер
- **RBS gateway:** `register.do` → `formUrl` → redirect
- **Нет inline card form.** Только hosted page
- **SBP inline:** `register.do` с `jsonParams.QRType=DYNAMIC_QR_SBP` → `sbpPayload` (NSPK ссылка). Генерируем QR на фронте
- **SberPay:** через `sberbankOnlineAttributes` в jsonParams — QR/deeplink
- **Amount в копейках.** Auth: `userName`/`password` или `token`
- **Callbacks ненадёжны** — всегда polling через `getOrderStatusExtended.do`

#### Альфа
- **= Сбер (RBS gateway).** Идентичное API, другой base URL (`pay.alfabank.ru`)
- **SBP:** `register.do` → `sbp/c2b/qr/dynamic/get.do` → base64 PNG или payload
- **Один коннектор `RbsConnector`** с настраиваемым base URL покрывает Сбер + Альфа (+ другие банки на RBS)
- **dynamicCallbackUrl** — можно задать webhook per-payment

#### Точка
- **Redirect-only** для карт. Нет виджета, нет iframe
- **Direct method:** `paymentMode` = `card` | `sbp` | `tinkoff` | `dolyame`
- **SBP QR:** отдельный API `/sbp/v1.0/qr-code/` — генерация dynamic/static QR
- **Webhooks:** JWT (RS256), верификация публичным ключом Точки
- **Процессинг через PayKeeper** для карт

---

## Архитектура

### 4 типа результата `createPaymentSession()`

```php
enum SessionResultType: string {
    case ServerRedirect = 'server_redirect';   // REST API → URL от PSP → redirect
    case FormRedirect = 'form_redirect';       // URL формируется локально → redirect (Робокасса)
    case EmbeddedWidget = 'embedded_widget';   // REST API → token/params → JS SDK на странице
    case QrInline = 'qr_inline';              // REST API → QR данные → показываем + polling
}
```

**Маппинг PSP → типы:**

| PSP | Card | SBP |
|-----|------|-----|
| ЮKassa | `server_redirect` (с `payment_method_data.type=bank_card`) | `server_redirect` (с type=sbp, redirect на QR page) |
| CloudPayments | `embedded_widget` (JS popup) | `embedded_widget` (JS popup, restricted) |
| Робокасса | `form_redirect` (MD5 подпись) | `form_redirect` (IncCurrLabel=SBP) |
| Т-Банк | `server_redirect` (Init → PaymentURL) | `qr_inline` (Init → GetQr → SVG) |
| Сбер/Альфа (RBS) | `server_redirect` (register.do → formUrl) | `qr_inline` (register.do → getSbpDynamicQr) |
| Точка | `server_redirect` (paymentMode=card) | `qr_inline` (SBP QR API) |

### PSP Capabilities (hardcoded в коде драйвера)

Каждый драйвер декларирует `capabilities()` — статический метод:

```php
// ConnectorCapabilities value object
new ConnectorCapabilities(
    defaultDisplayName: ['ru' => 'ЮKassa', 'en' => 'YooKassa'],
    logoPath: '/logos/yookassa.svg',
    directMethods: [
        'card' => new DirectMethod(sessionType: SessionResultType::ServerRedirect),
        'sbp'  => new DirectMethod(sessionType: SessionResultType::ServerRedirect),
    ],
    fallbackSessionType: SessionResultType::ServerRedirect,
    amountUnit: AmountUnit::Rubles,  // или AmountUnit::Kopecks для Т-Банк/Сбер/Альфа
)
```

**Мерчант НЕ настраивает capabilities.** Только:
- `connector_account_details` — credentials (encrypted)
- `payment_methods_enabled` — какие методы включены
- `display_config.display_name` — переопределение имени (optional)
- `display_config.logo_url` — переопределение логотипа (optional)

### PaymentSessionResult (полиморфный ответ)

```php
class PaymentSessionResult {
    public static function serverRedirect(string $url, string $method = 'GET', array $params = []): self;
    public static function formRedirect(string $url, array $params, string $method = 'POST'): self;
    public static function embeddedWidget(string $provider, string $scriptUrl, array $params): self;
    public static function qrInline(string $qrData, string $format, string $paymentId): self;
    // format: 'svg' | 'base64_png' | 'payload' (NSPK link)
}
```

### Логика API `/payment-methods` v2

```
Для каждого активного коннектора бизнес-профиля:
  1. Получить capabilities() из драйвера
  2. Получить payment_methods_enabled мерчанта
  3. Для каждого enabled метода:
     a. Есть ли direct method в capabilities? → добавить как direct
     b. Нет → добавить коннектор в connector_selection

Приоритет для SBP:
  - qr_inline > server_redirect (QR инлайн лучше чем redirect на QR page)

Если один коннектор + supports direct → показать методы
Если один коннектор + НЕ supports direct → сразу redirect (0 кликов)
Если несколько коннекторов → mixed (direct где возможно + connector_selection для остальных)
```

### API Response `/payment-methods` v2

```json
{
  "data": {
    "mode": "mixed",
    "methods": [
      {
        "method": "card",
        "display_name": "Банковская карта",
        "type": "direct",
        "connector": "yookassa",
        "session_type": "server_redirect"
      },
      {
        "method": "sbp",
        "display_name": "СБП",
        "type": "direct",
        "connector": "tbank",
        "session_type": "qr_inline"
      }
    ],
    "connectors": [
      {
        "connector_name": "robokassa",
        "display_name": "Робокасса",
        "logo_url": "/logos/robokassa.svg",
        "session_type": "form_redirect"
      }
    ]
  }
}
```

### Confirm Response — 4 типа

`POST /api/v1/payments/{key}/confirm`:

**server_redirect:**
```json
{
  "status": "requires_customer_action",
  "action_type": "redirect",
  "redirect_url": "https://yookassa.ru/pay/...",
  "redirect_method": "GET"
}
```

**form_redirect (Робокасса):**
```json
{
  "status": "requires_customer_action",
  "action_type": "form_redirect",
  "form_url": "https://auth.robokassa.ru/Merchant/Index.aspx",
  "form_method": "POST",
  "form_params": {
    "MerchantLogin": "...",
    "OutSum": "100.00",
    "InvId": "123",
    "SignatureValue": "abc123..."
  }
}
```

**embedded_widget (CloudPayments):**
```json
{
  "status": "requires_customer_action",
  "action_type": "widget",
  "widget_data": {
    "provider": "cloudpayments",
    "script_url": "https://widget.cloudpayments.ru/bundles/cloudpayments.js",
    "params": {"publicId": "pk_xxx", "amount": "100.00", "currency": "RUB"}
  }
}
```

**qr_inline (Т-Банк SBP):**
```json
{
  "status": "requires_customer_action",
  "action_type": "qr",
  "qr_data": {
    "format": "svg",
    "data": "<svg>...</svg>",
    "payment_id": "tbank_12345",
    "poll_url": "/api/v1/payments/{key}/status",
    "poll_interval_ms": 3000,
    "expires_at": "2026-03-23T12:05:00Z"
  }
}
```

### Виджет — обработка 4 типов

```
action_type === 'redirect'      → window.location.href = redirect_url
action_type === 'form_redirect' → создать <form>, заполнить hidden inputs, submit()
action_type === 'widget'        → загрузить script_url, вызвать SDK PSP
action_type === 'qr'            → показать QR код + polling до success/timeout
```

---

## Виджет UI — три сценария

**Один коннектор + direct methods:**
```
┌─────────────────────────┐
│ 100,00 ₽                │
│                         │
│ 💳 Оплатить картой      │  → direct
│ 📱 СБП                  │  → direct (QR inline если qr_inline)
│                         │
│ [Оплатить 100,00 ₽]     │
└─────────────────────────┘
```

**Один коннектор + НЕ supports direct:**
```
┌─────────────────────────┐
│ 100,00 ₽                │
│                         │
│ [Оплатить 100,00 ₽]     │  → сразу redirect на PSP
└─────────────────────────┘
```

**Несколько коннекторов, mixed:**
```
┌─────────────────────────┐
│ 100,00 ₽                │
│                         │
│ 💳 Оплатить картой      │  → direct (ЮKassa)
│ 📱 СБП                  │  → QR inline (Т-Банк)
│ ─── или ───             │
│ [logo] Робокасса        │  → form_redirect
│                         │
│ [Оплатить 100,00 ₽]     │
└─────────────────────────┘
```

---

## Коннектор RBS (Сбер + Альфа)

Один `RbsConnector` с настраиваемым base URL:

```php
// capabilities
'sberbank' => new ConnectorCapabilities(
    defaultDisplayName: ['ru' => 'Сбербанк', 'en' => 'Sberbank'],
    directMethods: [],  // метод выбирается на hosted page
    sbpMethod: new DirectMethod(sessionType: SessionResultType::QrInline),
    fallbackSessionType: SessionResultType::ServerRedirect,
    amountUnit: AmountUnit::Kopecks,
)

// connector_account_details
{
    "base_url": "https://securepayments.sberbank.ru",  // или https://pay.alfabank.ru
    "username": "merchant-api",
    "password": "secret",
    "token": null  // альтернатива username/password
}
```

---

## Steps

### Step 1: ConnectorCapabilities + PaymentSessionResult

- Добавить `ConnectorCapabilities` value object в `payment-connectors` пакет
- Добавить `PaymentSessionResult` value object с 4 типами
- Добавить `capabilities()` метод в `AbstractConnector`
- Имплементировать в каждом существующем драйвере (Stripe, CloudPayments, YooKassa, Test)
- Обновить `createPaymentSession()` — возвращает `PaymentSessionResult` вместо массива

### Step 2: Payment methods API v2

- Переписать `PaymentService::getAvailablePaymentMethods()`:
  - Для каждого коннектора: capabilities() ∩ payment_methods_enabled
  - Routing rules определяют какой коннектор для какого метода
  - Формирование response с `mode`, `methods[]`, `connectors[]`
- Обновить `PublicPaymentController::paymentMethods()`
- Обновить `PaymentMethodResource`

### Step 3: Confirm endpoint — полиморфный ответ

- `PaymentConfirmationService` обрабатывает `PaymentSessionResult` и формирует ответ по типу
- Для `form_redirect` — URL + params без HTTP запроса к PSP
- Для `qr_inline` — отдельный шаг (Init → GetQr для Т-Банка)
- Для `embedded_widget` — token/params из createPaymentSession

### Step 4: Widget v2 — 4 action types

- Обработка `redirect`, `form_redirect`, `widget`, `qr` типов
- QR режим: рендер SVG/PNG, polling через `/status`, таймер expiry
- Form redirect: динамическое создание `<form>` + submit
- Widget: lazy load скрипта PSP, вызов SDK

### Step 5: Новые коннекторы (Т-Банк, RBS, Робокасса, Точка)

- `TBankConnector` — Init, GetQr, GetState, Confirm, Cancel, Notifications
- `RbsConnector` — register.do, getOrderStatusExtended.do, getSbpDynamicQr.do (Сбер + Альфа)
- `RobokassaConnector` — form URL generation, MD5 подписи, ResultURL webhook
- `TochkaConnector` — Payment Links API, SBP QR API, JWT webhooks
- Каждый с `capabilities()`, тестами, webhook handler

### Step 6: Dashboard — connector config UI

- Capabilities preview (read-only) — integration modes, supported methods
- Display name override (ru/en)
- Logo URL override
- Payment methods enabled toggles (из capabilities)
- Webhook URL + инструкции для каждого PSP

### Step 7: Polling endpoint для QR

- `GET /api/v1/payments/{key}/status` — lightweight endpoint для polling
- Возвращает текущий статус платежа
- Rate limit: 1 req/sec per payment
- Widget поллит каждые 3 секунды до terminal state

---

## PSP Auth & Webhook Summary

| PSP | Credentials | Webhook верификация | Webhook надёжность |
|-----|------------|--------------------|--------------------|
| ЮKassa | `shop_id` + `secret_key` | IP whitelist + polling | Ретраи 24ч |
| CloudPayments | `public_id` + `api_secret` | HMAC подпись | Надёжно |
| Робокасса | `login` + `password1` + `password2` | MD5(OutSum:InvId:Password#2) | Надёжно |
| Т-Банк | `terminal_key` + `password` | SHA-256 Token | Ретраи |
| Сбер | `username`/`password` или `token` | Ненадёжно — **polling обязателен** | 3 попытки |
| Альфа | `username`/`password` или `token` | HMAC (symmetric key) | Ненадёжно — polling |
| Точка | OAuth JWT token | JWT RS256 (публичный ключ Точки) | 30 ретраев по 10сек |
