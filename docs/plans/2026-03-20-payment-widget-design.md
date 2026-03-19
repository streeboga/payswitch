# Payment Widget Design — @payswitch/js

**Дата**: 2026-03-20
**Статус**: Approved

## Цель

Создать встраиваемый платёжный виджет по паттерну HyperSwitch/Stripe: загрузчик SDK + UI-компонент, который мерчант монтирует в DOM-элемент на своём сайте. Также используется в дашборде (тест-платежи, карточка коннектора).

## Требования

- **Redirect-only** — без полей ввода карты, без PCI DSS scope
- **Встраиваемый** — `mount('#payment-element')` в любой DOM-элемент
- **Все enabled методы оплаты** — динамически из `payment_methods_enabled` коннекторов профиля
- **Отдельный пакет** `/widget/` — свой package.json, Vite config
- **Два контекста**: сайты мерчантов (production) + дашборд (тест-платежи, коннекторы)

## Архитектура

### 1. SDK загрузчик (`/widget/`)

Адаптация [juspay/hyper-js](https://github.com/juspay/hyper-js) — переписан на TypeScript.

```ts
import { loadPayswitch } from '@payswitch/js';

const ps = await loadPayswitch('pk_live_xxx', {
  customBackendUrl: 'https://api.payswitch.ru',  // опционально
  env: 'production',                                // или 'sandbox'
});

const widgets = ps.widgets({ clientSecret: 'pi_xxx_secret_yyy' });
const paymentElement = widgets.create('payment');
paymentElement.mount('#payment-element');

paymentElement.on('ready', () => { /* виджет загружен */ });
paymentElement.on('redirect', ({ url }) => { /* редирект на PSP */ });

const result = await ps.confirmPayment({
  widgets,
  confirmParams: { return_url: 'https://merchant.com/result' },
});
```

**Отличия от hyper-js:**
- TypeScript вместо ReScript
- URL наших серверов вместо HyperSwitch CDN
- Типы адаптированы под наш API (PaymentIntent statuses, connector names)

### 2. Public API (бэкенд)

Новые эндпоинты, авторизованные через `publishable_key` (заголовок `api-key`) + `client_secret` (в body/query):

```
GET  /api/v1/payments/{paymentKey}                → статус + ограниченные поля
POST /api/v1/payments/{paymentKey}/confirm         → запуск redirect flow
GET  /api/v1/payments/{paymentKey}/payment-methods  → доступные методы оплаты
```

#### Middleware: `AuthenticateClientSecret`

- Валидирует `publishable_key` в заголовке `api-key` (уже поддерживается `ResolveApiKey`)
- Извлекает `client_secret` из body (POST) или query param (GET)
- Находит PaymentIntent по `paymentKey`, сравнивает `client_secret` через `hash_equals`
- Проверяет `session_expiry` — если истёк, 403
- Устанавливает `request->attributes`: `payment_intent`, `merchant_id`

#### PublicPaymentController

- `show()` — ограниченные поля: `status`, `amount`, `currency`, `metadata.redirect_url`, `metadata.redirect_method`. Без `client_secret`, без `connector_account_details`
- `confirm()` — те же правила что `ConfirmPaymentRequest`, `merchant_id` из middleware
- `paymentMethods()` — список доступных методов из `payment_methods_enabled` активных коннекторов профиля

#### CORS

Публичные эндпоинты должны принимать запросы с любого домена мерчанта.

#### Rate limit

`payswitch-public` — 60 req/min (уже настроен для publishable tier).

### 3. Widget UI (рендер)

Легковесный UI на Preact, загружаемый SDK:

- Список доступных методов оплаты (иконки + названия)
- Кнопка "Оплатить {amount} {currency}" → confirm → redirect на PSP
- Loading / error / success состояния
- Обработка `return_url` + отображение статуса

### Структура `/widget/`

```
/widget/
├── package.json              # @payswitch/js
├── tsconfig.json
├── vite.config.ts            # library mode (ESM + UMD)
├── src/
│   ├── index.ts              # loadPayswitch() — точка входа
│   ├── types.ts              # TypeScript типы (адаптация HyperSwitch index.d.ts)
│   ├── payswitch.ts          # PayswitchInstance class
│   ├── elements.ts           # Elements/Widgets — create, mount, events
│   ├── api.ts                # HTTP client для Public API
│   └── ui/                   # Preact — рендер payment methods
│       ├── PaymentWidget.tsx
│       └── styles.css
└── dist/
    ├── payswitch.js          # UMD бандл (для <script>)
    ├── payswitch.mjs         # ESM бандл (для import)
    └── payswitch.d.ts        # типы
```

## API контракт (по паттерну HyperSwitch)

### PayswitchInstance

```ts
interface PayswitchInstance {
  widgets(options: WidgetOptions): WidgetCollection;
  confirmPayment(params: ConfirmPaymentParams): Promise<ConfirmPaymentResult>;
  retrievePaymentIntent(clientSecret: string): Promise<PaymentIntentResult>;
}
```

### WidgetCollection

```ts
interface WidgetCollection {
  create(type: 'payment', options?: CreateOptions): PaymentWidget;
  getElement(type: string): PaymentWidget | null;
  update(options: Partial<WidgetOptions>): void;
}
```

### PaymentWidget

```ts
interface PaymentWidget {
  mount(selector: string): void;
  unmount(): void;
  destroy(): void;
  on(event: 'ready' | 'change' | 'redirect' | 'error', handler: EventHandler): void;
  update(options: object): void;
}
```

### Типы

```ts
interface WidgetOptions {
  clientSecret: string;
  appearance?: AppearanceOptions;
  locale?: string;
}

interface ConfirmPaymentParams {
  widgets: WidgetCollection;
  confirmParams: { return_url: string };
  redirect?: 'always' | 'if_required';
}

type ConfirmPaymentResult =
  | { status: 'succeeded'; paymentIntent: PaymentIntentResponse }
  | { status: 'requires_customer_action'; redirectUrl: string }
  | { status: 'error'; error: { type: string; message: string } };
```

## План тестирования

### Бэкенд (Pest PHP)

| Тест | Что проверяем |
|------|---------------|
| `AuthenticateClientSecretMiddleware` | Валидный / невалидный / expired client_secret, отсутствие client_secret |
| `PublicPaymentController@show` | Ограниченные поля, без sensitive data, 404 для чужого платежа |
| `PublicPaymentController@confirm` | Redirect flow через public API, fallback на следующий коннектор |
| `PublicPaymentController@paymentMethods` | Список методов из enabled коннекторов, пустой при отсутствии коннекторов |
| CORS | Заголовки `Access-Control-Allow-Origin` для публичных эндпоинтов |

### Фронтенд (Vitest)

| Тест | Что проверяем |
|------|---------------|
| `loadPayswitch()` | Загрузка SDK, защита от дублей скрипта, обработка ошибок |
| `PayswitchInstance` | `widgets()`, `confirmPayment()`, `retrievePaymentIntent()` |
| `PaymentWidget` | mount/unmount, события (ready, change, error), рендер методов оплаты |
| `api.ts` | HTTP клиент: правильные заголовки, обработка ошибок, retry |

### E2E (Playwright)

| Тест | Что проверяем |
|------|---------------|
| Full payment flow | Create PI → mount widget → select method → confirm → redirect → return |
| Error handling | Expired session, network error, invalid client_secret |
| Dashboard integration | Тест-платёж через виджет в дашборде |

## Зависимости от существующей системы

| Компонент | Статус | Нужно |
|-----------|--------|-------|
| `publishable_key` у мерчантов | Есть | — |
| `client_secret` генерация | Есть | — |
| `ResolveApiKey` для publishable | Есть | — |
| Rate limit для publishable | Есть (60/min) | — |
| Redirect flow (`createPaymentSession`) | Есть | — |
| `payment_methods_enabled` на коннекторах | Есть | — |
| **Public API эндпоинты** | **Нет** | Новые роуты + контроллер |
| **AuthenticateClientSecret middleware** | **Нет** | Новый middleware |
| **CORS для public API** | **Нет** | Настройка Laravel CORS |
| **Widget UI** | **Нет** | Новый пакет `/widget/` |
