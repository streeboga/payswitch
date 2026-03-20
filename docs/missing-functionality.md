# Отсутствующий функционал Payswitch

Что нужно для прода, но НЕ реализовано. Payswitch — redirect-based роутер: карты не хранятся, 3DS на стороне PSP, оплата через платёжные формы коннекторов.

## Исправленные баги (8/8)

| # | Баг | Статус |
|---|-----|--------|
| ~~1~~ | ~~payment_id не передаётся коннекторам~~ | **FIXED** |
| ~~2~~ | ~~3DS redirect в YooKassa~~ | **FIXED** |
| ~~3~~ | ~~3DS redirect в CloudPayments~~ | **FIXED** |
| ~~4~~ | ~~Stripe через Omnipay~~ | **FIXED** — переписан на PaymentIntents API |
| ~~5~~ | ~~Refund webhook игнорируется~~ | **FIXED** |
| ~~6~~ | ~~Webhook не обновляет connector metadata~~ | **FIXED** |
| ~~7~~ | ~~customer_id не валидируется~~ | **FIXED** |
| ~~8~~ | ~~capture не передаёт currency~~ | **FIXED** |

## HIGH — нужно для прода

| # | Функционал | Статус | Детали |
|---|-----------|--------|--------|
| ~~9~~ | ~~Payment sync endpoint~~ | **DONE** | `POST /api/v1/payments/{id}/sync` + `getPaymentStatus()` в ConnectorInterface |
| ~~10~~ | ~~Redirect-based payment flow~~ | **DONE** | `createPaymentSession()` — confirm без card data возвращает redirect_url |
| ~~11~~ | ~~Return URL handling~~ | **DONE** | payment_id добавляется в return_url query params |
| ~~12~~ | ~~Void/cancel at connector~~ | **DONE** | `void()` в ConnectorInterface, cancel() использует void вместо refund |
| ~~13~~ | ~~Payment expiry cleanup~~ | **DONE** | `CleanExpiredPaymentsJob` — каждые 5 минут через scheduler |
| ~~14~~ | ~~Webhook idempotency~~ | **DONE** | Terminal-state guard для refund webhooks |
| ~~15~~ | ~~Connector redirect params~~ | **DONE** | `redirect_method` (GET/POST) + `redirect_params` в ответах коннекторов |

## HIGH — Dashboard CRUD gaps (аудит 2026-03-20)

| # | Раздел | Баг | Что нужно |
|---|--------|-----|-----------|
| 29 | **Connectors** | `connector_account_details` не возвращается в `ConnectorResource` | Маскированные credentials + `webhook_url` в ресурсе |
| 30 | **Connectors** | `POST /connectors/{key}/test` — роут не существует, 404 | `testConnection()` в ConnectorInterface + роут + контроллер |
| 31 | **Connectors** | Нет webhook URL и инструкций по настройке PSP | Карточки на странице коннектора + шаг в визарде |
| 32 | **API Keys** | `DashboardApiKeyController::store()` не вызывает `.additional()` | Ключ отдаётся в хедере, а не в теле — диалог "покажем один раз" пустой |
| 33 | **Profiles** | `payment_response_hash_key` не показывается в UI | Добавить на profile-detail с кнопкой копирования |
| 34 | **Routing Rules** | Нет UI для редактирования правил | Edit dialog + десериализация `rules[]` в формы |
| 35 | **Routing Rules** | `business_profile_id` orphaned | Вернуть в ресурсе, добавить в формы |
| 36 | **Merchant Detail** | Табы загружают все данные без фильтрации по мерчанту | Фильтрация child-ресурсов по merchant_id |
| 37 | **TypeScript types** | Не хватает `merchants_count`, `profiles_count`, `connectors_count` в типах | Обновить интерфейсы |

## HIGH — Payment Widget (2026-03-20)

| # | Функционал | Статус | Детали |
|---|-----------|--------|--------|
| ~~38~~ | ~~Public API (publishable key + client_secret)~~ | **DONE** | `AuthenticateClientSecret` middleware, `PublicPaymentController` (show, confirm, paymentMethods) |
| ~~39~~ | ~~@payswitch/js SDK~~ | **DONE** | `loadPayswitch()` → `widgets()` → `create('payment')` → `mount('#el')`. Preact UI, Vite library (ESM + UMD), 5 kB |
| ~~40~~ | ~~Dashboard widget integration~~ | **DONE** | `WidgetPreview` на странице test-payment, dynamic import |
| ~~41~~ | ~~CORS for widget~~ | **DONE** | `allowed_origins: ['*']`, `supports_credentials: false` |
| 42 | **Widget theming/appearance** | Нужно | `AppearanceOptions` в типах есть, UI не использует |
| 43 | **Widget i18n** | Нужно | `locale` в `WidgetOptions` есть, UI hardcoded EN |
| 44 | **Hosted Checkout Page** | Нужно | Отдельная страница на нашем домене для мерчантов без фронтенда |

## MEDIUM — улучшения

| # | Функционал | Что нужно |
|---|-----------|-----------|
| 16 | **Множественные payment methods** | Сейчас только card. Нужна поддержка: bank_transfer, sbp (СБП), qr_code — через redirect на PSP. |
| 17 | **Dispute/chargeback webhook** | DB таблицы есть, webhook приём не реализован. |
| 18 | **API backward compatibility** | Scramble OpenAPI diff в CI — проверка что PR не ломает API. |
| 19 | **Payouts (выплаты)** | Bank transfer payouts. Отдельный flow. |

## LOW — nice to have

| # | Функционал | Детали |
|---|-----------|--------|
| ~~20~~ | ~~k6 нагрузочные тесты~~ | **DONE** — loadtest/k6/ |
| 21 | Connector health auto-monitoring | Dashboard widget есть, автоматических alert'ов нет |
| 22 | Multi-currency settlement reporting | Нет конвертации/отчётности |

## FUTURE — не в MVP, но архитектура должна поддерживать

Сейчас redirect-based (карты вводятся на стороне PSP). Но система должна быть готова к расширению до прямой обработки. ConnectorInterface, routing, state machine — всё должно работать и для этих сценариев.

| # | Функционал | Готовность архитектуры | Что нужно для включения |
|---|-----------|----------------------|------------------------|
| 23 | **Raw card data / PCI DSS** | ConnectorInterface.purchase() уже принимает card data | Добавить PCI-compliant vault, TLS pinning, audit logging |
| 24 | **Mandates / recurring** | PaymentIntent имеет `customer_id`, ConnectorInterface extensible | Добавить Mandate модель, `setup_future_usage` param, cron для recurring |
| 25 | **Saved cards / tokenization** | Модель PaymentMethod существует, Customer CRUD есть | Добавить PaymentMethodService CRUD, токенизация через PSP |
| 26 | **Wallets (Apple Pay, Google Pay)** | `payment_method` field поддерживает любые типы | Добавить wallet connector params, domain verification endpoints |
| 27 | **BNPL (Klarna, Afterpay)** | Routing rules могут фильтровать по payment_method | Добавить BNPL коннекторы, redirect flow уже работает |
| 28 | **iDEAL, SEPA, ACH** | Redirect flow готов, multi-currency работает | Добавить bank transfer коннекторы |

---

*Обновляется по мере работы. Зачёркнутые пункты — сделаны.*
