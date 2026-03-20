# Widget V2 — Connector Config + Smart Payment Method Logic

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Переделать логику виджета: вместо плоского списка методов (card, sbp, apple_pay) — умный выбор на основе конфигурации коннекторов и возможностей PSP.

**Architecture:** Конфиг коннектора на сервере определяет что и как показывает виджет.

---

## Проблема текущей реализации

Сейчас виджет показывает `["card", "sbp", "apple_pay", "google_pay", "bank_transfer"]` — плоский список из `payment_methods_enabled`. Это неправильно:

1. Не все PSP поддерживают прямой redirect на конкретный метод
2. CloudPayments работает через виджет (JS popup), не через redirect
3. Если PSP не поддерживает прямой метод — нужно показывать PSP, а не методы
4. Нет конфига для каждого коннектора

## Как должно работать

### Два режима отображения в виджете

**Режим 1 — Direct Method Selection (если роутинг + PSP поддерживают):**

Роутинг настроен: SBP → CloudPayments, карта → YooKassa.
PSP поддерживают прямой redirect/widget на конкретный метод.

Виджет показывает:
```
┌─────────────────────────┐
│ 100,00 ₽                │
│                         │
│ 💳 Оплатить картой      │  → YooKassa redirect (type: bank_card)
│ 📱 СБП                  │  → CloudPayments SBP API (QR)
│                         │
│ [Оплатить 100,00 ₽]     │
└─────────────────────────┘
```

По клику — сразу оплата без лишних шагов.

**Режим 2 — PSP Selection (если прямой метод невозможен):**

Нет роутинга по методу, или PSP не поддерживает прямой redirect.

Виджет показывает платёжные системы:
```
┌─────────────────────────┐
│ 100,00 ₽                │
│                         │
│ [logo] CloudPayments    │  → opens their widget on page
│ [logo] YooKassa         │  → redirect to their hosted page
│ [logo] Robokassa        │  → redirect to their hosted page
│                         │
│ [Оплатить 100,00 ₽]     │
└─────────────────────────┘
```

### Connector Config Structure

Каждый MerchantConnectorAccount получает `connector_config` (JSON):

```json
{
  "display_name": {"ru": "ЮKassa", "en": "YooKassa"},
  "logo_url": "/logos/yookassa.svg",
  "show_logo": true,
  "show_name": true,
  "integration_mode": "redirect",
  "supports_direct_methods": true,
  "direct_methods": {
    "card": {"api_param": "bank_card", "mode": "redirect"},
    "sbp": {"api_param": "sbp", "mode": "redirect"}
  },
  "custom_fields": {
    "shop_id": {"type": "string", "label": "Shop ID", "required": true},
    "show_installments": {"type": "boolean", "label": "Show installment option", "default": false}
  }
}
```

**Для CloudPayments:**
```json
{
  "display_name": {"ru": "CloudPayments", "en": "CloudPayments"},
  "logo_url": "/logos/cloudpayments.svg",
  "integration_mode": "widget",
  "supports_direct_methods": true,
  "direct_methods": {
    "card": {"mode": "widget", "restricted": []},
    "sbp": {"mode": "widget", "restricted": ["Card", "TinkoffPay", "MirPay", "SberPay"]}
  }
}
```

**Для Robokassa:**
```json
{
  "display_name": {"ru": "Робокасса", "en": "Robokassa"},
  "logo_url": "/logos/robokassa.svg",
  "integration_mode": "redirect",
  "supports_direct_methods": true,
  "direct_methods": {
    "card": {"api_param": "BankCard", "mode": "redirect"},
    "sbp": {"api_param": "SBP", "mode": "redirect"}
  }
}
```

### API Response — Payment Methods v2

`GET /api/v1/payments/{key}/payment-methods` возвращает:

```json
{
  "data": {
    "mode": "direct_methods",
    "methods": [
      {
        "method": "card",
        "display_name": "Банковская карта",
        "icon_url": null,
        "connector": "yookassa",
        "action_mode": "redirect"
      },
      {
        "method": "sbp",
        "display_name": "СБП",
        "icon_url": null,
        "connector": "cloudpayments",
        "action_mode": "widget"
      }
    ]
  }
}
```

Или если direct methods недоступен:

```json
{
  "data": {
    "mode": "connector_selection",
    "connectors": [
      {
        "connector_name": "cloudpayments",
        "display_name": "CloudPayments",
        "logo_url": "/logos/cloudpayments.svg",
        "action_mode": "widget"
      },
      {
        "connector_name": "yookassa",
        "display_name": "ЮKassa",
        "logo_url": "/logos/yookassa.svg",
        "action_mode": "redirect"
      }
    ]
  }
}
```

### Confirm Response — Widget vs Redirect

`POST /api/v1/payments/{key}/confirm` с `connector: "cloudpayments"`:

**Redirect mode:**
```json
{
  "status": "requires_customer_action",
  "metadata": {
    "redirect_url": "https://yookassa.ru/pay/...",
    "redirect_method": "GET"
  }
}
```

**Widget mode (CloudPayments):**
```json
{
  "status": "requires_customer_action",
  "metadata": {
    "widget_data": {
      "script_url": "https://widget.cloudpayments.ru/bundles/cloudpayments.js",
      "params": {
        "publicId": "pk_xxx",
        "amount": "100.00",
        "currency": "RUB",
        "description": "Payment"
      }
    }
  }
}
```

Виджет рендерит:
- Redirect → `window.location.href = redirect_url`
- Widget → создаёт `<script>` тег CloudPayments и открывает их popup

---

## Steps

### Step 1: Connector config schema + migration

- Rename `display_config` → `connector_config` (или расширить)
- Добавить default configs для known connectors (stripe, cloudpayments, yookassa, test)
- Dashboard: кастомные поля в настройках коннектора из `custom_fields`

### Step 2: Payment methods API v2

- Логика: проверить роутинг → если есть правила по методам И PSP поддерживают direct → mode: "direct_methods"
- Иначе → mode: "connector_selection" с PSP list
- `action_mode` берётся из connector_config

### Step 3: Widget v2 — dual mode render

- Режим direct_methods: показывает методы оплаты
- Режим connector_selection: показывает PSP с логотипами
- Confirm: обрабатывает redirect и widget responses

### Step 4: Widget embedded PSP support

- CloudPayments widget integration
- Script injection, параметры из widget_data

### Step 5: Connector config UI в дашборде

- Custom fields рендеринг из connector_config.custom_fields
- Logo upload/URL
- Display name editing
- Direct methods toggle

### Step 6: Connector default configs

- Seed/migration с default connector_config для каждого известного PSP
- ConnectorFactory учитывает config при createPaymentSession

---

## PSP Research Summary (из предыдущей сессии)

| PSP | Direct SBP | Direct Card | Widget Mode | API Param |
|-----|-----------|-------------|-------------|-----------|
| YooKassa | YES → QR page | YES → card form | YES (embedded) | `payment_method_data.type` |
| CloudPayments | YES (виджет) | YES (виджет) | YES (основной) | `restrictedPaymentMethods` |
| Robokassa | YES (soft) | YES (soft) | iframe | `IncCurrLabel` |
