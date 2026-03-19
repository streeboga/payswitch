# ТЗ: Production Readiness — Payswitch

## Контекст

Payswitch — redirect-based платёжный роутер. Клиент не вводит карту у нас — он перенаправляется на платёжную форму PSP (YooKassa, CloudPayments, Stripe Checkout). После оплаты PSP уведомляет нас через webhook.

Текущее состояние: базовый flow (create → confirm → webhook) работает. 785 backend тестов, 52 E2E, 8 production-багов исправлено. Но для прода не хватает 7 вещей.

---

## 1. Payment Sync — опрос PSP для получения статуса

### Проблема
После redirect клиента на PSP единственный способ узнать результат — ждать webhook. Если webhook потерялся или задержался — платёж вечно висит в `requires_customer_action`.

### Что сделать

**ConnectorInterface** — добавить метод:
```php
public function getPaymentStatus(array $params): array;
// params: ['transaction_id' => 'yk_123']
// return: ['status' => 'succeeded', 'transaction_id' => 'yk_123', 'data' => [...]]
```

**Реализовать в каждом коннекторе:**
- YooKassa: `GET /v3/payments/{id}` → маппинг status
- CloudPayments: `POST /payments/find` → маппинг Status
- Stripe: `GET /v1/payment_intents/{id}` → маппинг status

**API endpoint:**
```
POST /api/v1/payments/{paymentKey}/sync
Headers: api-key: sk_...
Response: PaymentIntentResource (с обновлённым статусом)
```

**Логика:**
1. Найти платёж по key + merchant
2. Проверить: статус в `[requires_customer_action, processing]` — иначе 400
3. Найти последний payment_attempt → connector_transaction_id
4. Вызвать `connector->getPaymentStatus()`
5. Если статус изменился — обновить через state machine
6. Вернуть обновлённый PaymentIntent

**Scheduled job (опционально):**
`SyncPendingPaymentsJob` — раз в 5 минут находит платежи в `requires_customer_action` старше 2 минут, вызывает sync.

### Тесты
- Sync succeeded payment → статус обновляется
- Sync уже succeeded → 400 (nothing to sync)
- Sync, PSP ещё pending → статус не меняется
- Connector timeout → graceful error

---

## 2. Redirect-based Confirm Flow — убрать raw card data из confirm

### Проблема
Сейчас `POST /confirm` принимает `payment_method_data.card.card_number` — сырые данные карты. Для redirect-based модели confirm должен только инициировать платёж через PSP и вернуть redirect_url.

### Что сделать

**Текущий flow (оставить как есть для test mode):**
```
confirm + card_data → connector.purchase(card_data) → succeeded/requires_action
```

**Новый flow (redirect mode, для production):**
```
confirm + payment_method=card → connector.createSession() → redirect_url
→ клиент уходит на PSP → платит → PSP webhook → succeeded
```

**ConnectorInterface** — добавить метод:
```php
public function createPaymentSession(array $params): array;
// params: ['amount', 'currency', 'payment_id', 'return_url', 'payment_method']
// return: ['redirect_url' => 'https://...', 'session_id' => '...', 'code' => 'redirect']
```

**Реализовать:**
- YooKassa: создать платёж с `confirmation.type=redirect` → вернуть `confirmation.confirmation_url`
- CloudPayments: widget mode или `POST /orders/create` → вернуть widget URL
- Stripe: `POST /v1/checkout/sessions` → вернуть `url`

**PaymentConfirmationService.confirm():**
```
if (has payment_method_data with card) → старый flow (test mode)
if (only payment_method without card data) → redirect flow:
  1. connector.createPaymentSession(amount, currency, return_url, payment_method)
  2. set status = requires_customer_action
  3. store redirect_url in metadata
  4. return payment with redirect_url
```

**API response при redirect:**
```json
{
  "data": {
    "id": "pay_abc123",
    "attributes": {
      "status": "requires_customer_action",
      "metadata": {
        "redirect_url": "https://yookassa.ru/pay/abc123"
      }
    }
  }
}
```

### Тесты
- Confirm с payment_method без card data → requires_customer_action + redirect_url
- Confirm с card data (test mode) → старый flow работает
- Redirect URL корректен для каждого коннектора
- Return URL передаётся в PSP

---

## 3. Return URL handling — что делать когда клиент вернулся

### Проблема
После оплаты на PSP клиент возвращается на `return_url` мерчанта. Мерчант должен понять: платёж прошёл или нет. Сейчас единственный способ — GET payment и ждать webhook.

### Что сделать

Это **не наша проблема на бэкенде** — мерчант сам обрабатывает return. Но нужно:

1. **Убедиться что return_url всегда передаётся в PSP** — проверить каждый коннектор
2. **Добавить query params к return_url** — PSP часто не добавляют payment_id:
   ```
   return_url = merchant_url + ?payment_id=pay_abc123&status=pending
   ```
3. **Документировать в API docs** — мерчант после return должен:
   - Вызвать `GET /api/v1/payments/{id}` для текущего статуса
   - Или вызвать `POST /api/v1/payments/{id}/sync` для принудительного обновления
   - Или ждать webhook на свой endpoint

### Изменения в PaymentConfirmationService:
```php
// При формировании redirect, добавить payment_id в return_url
$returnUrl = $payment->return_url;
if ($returnUrl) {
    $separator = str_contains($returnUrl, '?') ? '&' : '?';
    $returnUrl .= $separator . 'payment_id=' . $payment->key;
}
$connectorParams['return_url'] = $returnUrl;
```

### Тесты
- return_url с payment_id query param передаётся коннектору
- return_url без query params → добавляется ?payment_id=...
- return_url с существующими query params → добавляется &payment_id=...
- return_url = null → ничего не ломается

---

## 4. Void вместо Refund при cancel

### Проблема
`PaymentService.cancel()` вызывает `connector->refund()` для авторизованных (RequiresCapture) платежей. Рефунд — это возврат денег. Void — это отмена авторизации (hold снимается мгновенно, без комиссии).

### Что сделать

**ConnectorInterface** — добавить метод:
```php
public function void(array $params): array;
// params: ['transaction_id' => 'pi_123', 'payment_id' => 'pay_abc']
// return: ['success' => true, 'transaction_id' => 'pi_123']
```

**Реализовать:**
- YooKassa: `POST /v3/payments/{id}/cancel` → cancel авторизации
- CloudPayments: `POST /payments/void` → void
- Stripe: `POST /v1/payment_intents/{id}/cancel` → cancel

**PaymentService.cancel():**
```php
// Было:
$connector->refund(['amount' => $payment->amount, 'transaction_id' => ...]);

// Стало:
$connector->void(['transaction_id' => ..., 'payment_id' => $payment->key]);
```

### Тесты
- Cancel авторизованного платежа → void вызван (не refund)
- Void на PSP вернул ошибку → cancel продолжает (graceful, как сейчас)
- Cancel succeeded платежа → void не вызывается (только local status change)

---

## 5. Payment Expiry Cleanup

### Проблема
`expires_on` проставляется при создании (default 900s = 15 минут). Но проверка только в `/confirm` — если мерчант не вызовет confirm, платёж вечно висит в `requires_payment_method`.

### Что сделать

**Scheduled Job:**
```php
// app/Jobs/CleanExpiredPaymentsJob.php
// Запуск: каждые 5 минут через scheduler

1. Найти платежи: expires_on < now() AND status IN [requires_payment_method, requires_confirmation, requires_customer_action]
2. Для каждого: transition → Expired через state machine
3. Для requires_customer_action: попробовать void на PSP (если есть transaction_id)
4. Log количество expired
```

**Scheduler** (routes/console.php):
```php
Schedule::job(CleanExpiredPaymentsJob::class)->everyFiveMinutes();
```

### Тесты
- Expired payment переходит в Expired
- Non-expired payment не трогается
- Already succeeded payment не трогается (даже если expires_on в прошлом)
- Expired requires_customer_action → void на PSP + Expired

---

## 6. Webhook Idempotency

### Проблема
Если PSP отправит один и тот же webhook дважды — второй раз state machine не даст перейти (уже в целевом статусе). Но: если первый webhook ещё обрабатывается (DB lock), второй будет ждать. Для refund webhooks: `Refund::where('connector_refund_id', $id)` может обновить статус дважды.

### Что сделать

**Минимальное решение** (state machine уже защищает payment transitions):

Для refund webhooks добавить проверку:
```php
// WebhookReceiverService.processRefundWebhook()
$refund = Refund::where('connector_refund_id', $connectorRefundId)->first();
if (!$refund || $refund->status === RefundStatus::Succeeded) {
    return; // уже обработан
}
```

**Опционально** — таблица processed webhooks:
```
incoming_webhooks: id, connector, external_event_id, processed_at
UNIQUE INDEX (connector, external_event_id)
```

Перед обработкой: `INSERT IGNORE` — если уже есть, skip.

### Тесты
- Дублирующий webhook не меняет статус повторно
- Дублирующий refund webhook не обновляет refund повторно
- Concurrent webhooks: один обрабатывается, второй пропускается

---

## 7. Connector-Specific Redirect Params

### Проблема
Каждый PSP возвращает redirect по-своему. Сейчас коннекторы возвращают `data.redirect_url`, но для CloudPayments 3DS нужен ещё POST с PaReq, а для widget mode — другие параметры.

### Что сделать

**Унифицированный формат redirect response от коннектора:**
```php
[
    'code' => 'redirect',
    'redirect_url' => 'https://...',
    'redirect_method' => 'GET', // или 'POST'
    'redirect_params' => [],     // для POST: form fields (PaReq, MD, TermUrl)
    'session_id' => '...',       // для widget mode
]
```

**Обновить коннекторы:**
- YooKassa `createPaymentSession()`: redirect_method=GET, redirect_url=confirmation_url
- CloudPayments 3DS: redirect_method=POST, redirect_params=[PaReq, MD], redirect_url=AcsUrl
- CloudPayments widget: redirect_method=GET, session_id для iframe
- Stripe Checkout: redirect_method=GET, redirect_url=checkout session URL

**PaymentConfirmationService:** сохранять `redirect_method` и `redirect_params` в metadata.

### Тесты
- YooKassa redirect → method=GET, url present
- CloudPayments 3DS → method=POST, PaReq+MD in params
- Stripe checkout → method=GET, url present

---

## Приоритет реализации

| # | Задача | Сложность | Зависимости |
|---|--------|-----------|-------------|
| **5** | Payment Expiry Cleanup | Простая | Нет |
| **6** | Webhook Idempotency (refund guard) | Простая | Нет |
| **4** | Void вместо Refund | Средняя | ConnectorInterface change |
| **1** | Payment Sync | Средняя | ConnectorInterface + new endpoint |
| **3** | Return URL params | Простая | Нет |
| **2** | Redirect-based Confirm | Сложная | ConnectorInterface + new method + PSP integration |
| **7** | Connector Redirect Params | Средняя | Зависит от #2 |

Рекомендуемый порядок: 5 → 6 → 4 → 3 → 1 → 2 → 7

---

## Definition of Done

- [ ] Каждая задача покрыта тестами (TDD — failing test first)
- [ ] Все существующие 785 тестов проходят
- [ ] `./vendor/bin/pint` clean
- [ ] API endpoints задокументированы в Scramble
- [ ] `docs/missing-functionality.md` обновлён
