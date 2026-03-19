# Отсутствующий функционал Payswitch

Что есть у Hyperswitch / нужно для прода, но НЕ реализовано у нас.

## CRITICAL — без этого не пойдём в прод

| # | Функционал | Статус | Детали |
|---|-----------|--------|--------|
| ~~1~~ | ~~payment_id не передаётся коннекторам~~ | **FIXED** | BUG #1, #5 — PaymentConfirmationService + RefundService |
| ~~2~~ | ~~3DS redirect в YooKassa~~ | **FIXED** | BUG #2 — pending + confirmation_url → requires_action |
| ~~3~~ | ~~3DS redirect в CloudPayments~~ | **FIXED** | BUG #3 — AcsUrl → requires_action |
| ~~4~~ | ~~Stripe через Omnipay~~ | **FIXED** | BUG #4, #17 — переписан на PaymentIntents API |
| ~~5~~ | ~~Refund webhook игнорируется~~ | **FIXED** | BUG #6 — WebhookReceiverService |
| ~~6~~ | ~~Webhook не обновляет connector metadata~~ | **FIXED** | BUG #9 |
| ~~7~~ | ~~customer_id не валидируется~~ | **FIXED** | BUG #13 |
| ~~8~~ | ~~capture не передаёт currency~~ | **FIXED** | BUG #10 |

## HIGH — нужно для production-ready системы

| # | Функционал | Где должно быть | Что нужно сделать |
|---|-----------|----------------|-------------------|
| 9 | **Payment sync endpoint** | `POST /api/v1/payments/{id}/sync` | Polling PSP для обновления статуса. Hyperswitch делает sync после каждого confirm. |
| 10 | **Payment method tokenization (saved cards)** | `PaymentMethodService` | Customer → saved payment methods. Модель `PaymentMethod` есть, но нет CRUD API и flow сохранения. |
| 11 | **Mandates (recurring payments)** | `MandateService` | Single-use и multi-use мандаты. Нет модели, нет логики. Hyperswitch тестирует это отдельно. |
| 12 | **Void/cancel at connector** | `PaymentService.cancel()` | cancel() вызывает refund() на коннекторе вместо void(). Нужен отдельный `void()` в ConnectorInterface. |
| 13 | **Payment expiry cleanup** | `CleanExpiredPaymentsJob` | Платежи с истёкшим expires_on не переводятся в Expired. Нужен scheduled job. |
| 14 | **Webhook idempotency** | `WebhookReceiverService` | Дупликаты webhook'ов обрабатываются повторно. Нужна дедупликация по event_id. |

## MEDIUM — улучшения

| # | Функционал | Где должно быть | Что нужно сделать |
|---|-----------|----------------|-------------------|
| 15 | **Payouts (выплаты)** | `PayoutService` | Bank transfer, card payouts. Нет ничего. |
| 16 | **Bank transfers / redirects** | Connectors | iDEAL, SEPA, ACH — только карты сейчас |
| 17 | **Wallets** | Connectors | Apple Pay, Google Pay — нет |
| 18 | **BNPL** | Connectors | Klarna, Afterpay — нет |
| 19 | **Dispute/chargeback handling** | `DisputeService` | DB таблицы есть, webhook приём не реализован |
| 20 | **API backward compatibility** | CI | Scramble OpenAPI diff — проверка что PR не ломает API |
| 21 | **Payment list filtering/sorting API** | `PaymentController.index` | Spatie QueryBuilder настроен, но не все фильтры задокументированы |

## LOW — nice to have

| # | Функционал | Детали |
|---|-----------|--------|
| 22 | Нагрузочные тесты (k6) | p95 < 500ms для confirm |
| 23 | Connector health monitoring | Dashboard widget есть, но нет автоматических проверок |
| 24 | Multi-currency settlement reporting | Нет конвертации/отчётности |
| 25 | PCI DSS audit trail | Audit log есть, но не всё логируется |

---

*Обновляется по мере работы. Зачёркнутые пункты — починены.*
