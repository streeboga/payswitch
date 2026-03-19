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

| # | Функционал | Где | Что нужно |
|---|-----------|-----|-----------|
| 9 | **Payment sync endpoint** | `POST /api/v1/payments/{id}/sync` | Polling PSP для обновления статуса. После redirect клиента на PSP, мерчант опрашивает нас, мы опрашиваем PSP. |
| 10 | **Redirect-based payment flow** | `PaymentConfirmationService` | Сейчас confirm принимает card data напрямую. Нужен flow: confirm → получить redirect_url от PSP → вернуть мерчанту → клиент платит на стороне PSP → webhook. |
| 11 | **Return URL handling** | `PaymentController` | После оплаты на PSP клиент возвращается на return_url. Нужен endpoint `/api/v1/payments/{id}/return` для финализации. |
| 12 | **Void/cancel at connector** | `PaymentService.cancel()` | cancel() вызывает refund() вместо void(). Нужен `void()` в ConnectorInterface. |
| 13 | **Payment expiry cleanup** | `CleanExpiredPaymentsJob` | Платежи с истёкшим `expires_on` не переводятся в Expired. Нужен scheduled job. |
| 14 | **Webhook idempotency** | `WebhookReceiverService` | Дупликаты webhook'ов обрабатываются повторно. Дедупликация по event_id. |
| 15 | **Connector-specific redirect params** | Connectors | Каждый PSP отдаёт redirect по-своему: YooKassa — confirmation_url, CloudPayments — AcsUrl или 3DS form, Stripe — checkout session URL. Унифицировать в `redirect_url` + `redirect_method` (GET/POST). |

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

## Не нужно (out of scope)

| Функционал | Почему не нужно |
|-----------|----------------|
| Raw card data / PCI DSS scope | Redirect-based: карты вводятся на стороне PSP |
| Mandates / recurring | Подписки пока не в scope |
| Saved cards / tokenization | Не храним card data |
| Wallets (Apple Pay, Google Pay) | Через PSP checkout — не наша ответственность |
| BNPL (Klarna, Afterpay) | Через PSP checkout |
| iDEAL, SEPA, ACH | Через PSP redirect |

---

*Обновляется по мере работы. Зачёркнутые пункты — сделаны.*
