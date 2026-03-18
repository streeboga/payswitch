# Стратегия тестирования Payswitch на основе Hyperswitch

## Обзор: что тестирует Hyperswitch

Hyperswitch — open-source платёжный роутер на Rust. Их тестовая инфраструктура включает **6 уровней** тестирования:

| Уровень | Фреймворк | Кол-во тестов | Что проверяют |
|---------|-----------|--------------|---------------|
| Unit-тесты | Rust `#[test]` | ~100+ | Типы, криптография, PII-маскирование, валидация карт, ID-генерация, сериализация enum'ов |
| Интеграционные | actix-web test | ~150+ | Health check, Redis, полный цикл платежа, возвраты, кэш |
| Коннекторные | actix-web test | **140+ файлов** | Авторизация, capture, void, refund, sync, 3DS, мандаты — для каждого из 140+ коннекторов |
| E2E API (Cypress) | Cypress 14.5 | ~50+ спеков | Полные API-флоу: платежи, возвраты, роутинг, мандаты, выплаты, вебхуки |
| API (Postman/Newman) | Newman | 36 коллекций | Happy path + edge cases для каждого коннектора |
| Нагрузочные | k6 | 4 сценария | Throughput платежей, p90 < 500ms, health check под нагрузкой |
| UI/Browser | Selenium (thirtyfour) | 26 файлов | 3DS-редиректы, банковские переходы, QR-коды |

---

## Что уже есть у нас (Payswitch)

| Уровень | Фреймворк | Кол-во | Что покрыто |
|---------|-----------|--------|-------------|
| Unit | Pest PHP | ~10 | Коннекторы (CloudPayments, YooKassa), enum'ы, стейт-машина платежей, webhook signer |
| Feature (интеграц.) | Pest PHP | ~44 | API CRUD (admin, payments, refunds, webhooks, customers), дашборд, роутинг |
| Frontend unit | Vitest | ~54 | Компоненты, сторы, хуки, API-клиент |
| E2E | Playwright | 6 | Авторизация, формы, навигация, smoke-pages |

---

## Что можно перенять: конкретный план

### 1. Коннекторные тесты (ПРИОРИТЕТ: ВЫСОКИЙ)

**У Hyperswitch:** 140+ файлов, каждый тестирует один коннектор по единому интерфейсу — authorize, capture, void, refund, sync, 3DS, mandates.

**У нас:** Только 4 unit-теста на коннекторы (CloudPayments, YooKassa, Test, Factory).

**Что сделать:**

```
tests/Feature/Connectors/
├── ConnectorTestCase.php          # Базовый класс с общими тестами
├── CloudPaymentsTest.php
├── YooKassaTest.php
├── SberbankTest.php
├── TinkoffTest.php
└── ... (по файлу на коннектор)
```

Базовый класс `ConnectorTestCase` должен определять стандартный набор тестов:

```php
abstract class ConnectorTestCase extends ApiTestCase
{
    abstract protected function connectorName(): string;
    abstract protected function paymentData(): array;

    public function test_authorize_payment(): void { ... }
    public function test_capture_payment(): void { ... }
    public function test_void_payment(): void { ... }
    public function test_full_refund(): void { ... }
    public function test_partial_refund(): void { ... }
    public function test_sync_payment_status(): void { ... }
    public function test_3ds_flow(): void { ... }
    public function test_webhook_signature_verification(): void { ... }
}
```

Каждый коннектор наследует и переопределяет только данные (карты, ключи, ожидаемые статусы).

**Два режима работы:**
- **Mock-режим** (CI, по умолчанию): Http::fake() с записанными ответами коннекторов
- **Live-режим** (ручной запуск): реальные тестовые среды коннекторов

> **Аналог Hyperswitch:** `crates/router/tests/connectors/` + `connector_auth.toml`

---

### 2. Полный жизненный цикл платежа (ПРИОРИТЕТ: ВЫСОКИЙ)

**У Hyperswitch:** `integration_demo.rs` — создание мерчанта → API-ключ → платёж → возврат в одном тесте.

**У нас:** Есть `FullPaymentFlowTest`, но нужно расширить.

**Что добавить:**

```
tests/Feature/Api/Payments/
├── PaymentLifecycleTest.php        # ✅ уже есть
├── PaymentCaptureFlowTest.php      # auto-capture + manual capture
├── Payment3dsFlowTest.php          # 3DS challenge + callback
├── PaymentMandateTest.php          # single-use + multi-use мандаты
├── PaymentRetryTest.php            # retry при ошибке коннектора
├── PaymentIdempotencyTest.php      # дедупликация по idempotency key
└── PaymentCurrencyTest.php         # мульти-валютность
```

---

### 3. Роутинг и smart-routing (ПРИОРИТЕТ: ВЫСОКИЙ)

**У Hyperswitch:** 4 Cypress-спека: PriorityRouting, VolumeBasedRouting, RuleBasedRouting, Retries.

**У нас:** 2 теста (RoutingServiceTest, SmartRoutingTest).

**Что добавить:**

```
tests/Feature/Services/
├── RoutingServiceTest.php           # ✅ есть
├── SmartRoutingTest.php             # ✅ есть
├── PriorityRoutingTest.php          # приоритетная маршрутизация
├── VolumeBasedRoutingTest.php       # распределение по объёму
├── RuleBasedRoutingTest.php         # правила по карте/валюте/стране
├── RoutingFailoverTest.php          # fallback при отказе коннектора
└── RoutingPerformanceTest.php       # скорость выбора маршрута
```

---

### 4. Вебхуки: приём и доставка (ПРИОРИТЕТ: СРЕДНИЙ)

**У Hyperswitch:** фикстуры вебхуков для каждого коннектора + UI-тесты верификации.

**Что добавить:**

```
tests/Feature/Api/Webhooks/
├── WebhookTest.php                  # ✅ есть
├── WebhookReceiverTest.php          # ✅ есть
├── DeliverWebhookJobTest.php        # ✅ есть
├── WebhookRetryTest.php             # повторная доставка при ошибке
├── WebhookSignatureTest.php         # верификация подписей всех коннекторов
├── WebhookIdempotencyTest.php       # дедупликация событий
└── fixtures/
    ├── cloudpayments_payment.json
    ├── yookassa_payment.json
    └── ... (JSON-фикстуры от каждого коннектора)
```

---

### 5. Тесты мульти-тенантности и изоляции (ПРИОРИТЕТ: СРЕДНИЙ)

**У Hyperswitch:** Cypress тестирует создание организации → мерчанта → бизнес-профиля → коннектора.

**У нас:** Есть TenantHierarchyTest. Расширить:

```
tests/Feature/Api/
├── TenantHierarchyTest.php          # ✅ есть
├── TenantIsolationTest.php          # мерчант A не видит данные мерчанта B
├── ApiKeyScopeTest.php              # ключ работает только в рамках своего мерчанта
├── CrossTenantAccessTest.php        # защита от горизонтальной эскалации
```

---

### 6. Стейт-машина платежей (ПРИОРИТЕТ: СРЕДНИЙ)

**У Hyperswitch:** Тестируют каждый переход статуса: created → processing → authorized → captured/voided → refunded.

**Расширить наш `PaymentStateMachineTest`:**

```php
// Все допустимые переходы
test('payment transitions through valid states', function () {
    // created → requires_payment_method → requires_confirmation → processing
    // → requires_capture → captured → partially_refunded → refunded
});

// Все запрещённые переходы
test('payment rejects invalid transitions', function () {
    // captured → processing (нельзя)
    // refunded → captured (нельзя)
    // failed → captured (нельзя)
});

// Таймауты и зависшие платежи
test('expired payments are cleaned up', function () { ... });
```

---

### 7. Нагрузочное тестирование (ПРИОРИТЕТ: НИЗКИЙ, но важный)

**У Hyperswitch:** k6 — 25 VU, p90 < 500ms, Grafana-дашборды.

**Что сделать для нас:**

```
loadtest/
├── k6/
│   ├── payment-create.js            # создание платежа
│   ├── payment-confirm.js           # подтверждение
│   ├── health.js                    # здоровье сервера
│   └── helpers/
│       └── setup.js                 # создание тестового мерчанта
├── docker-compose.yml               # k6 + InfluxDB + Grafana
└── README.md
```

Пороги:
- Health check: p95 < 50ms
- Payment create: p95 < 300ms
- Payment confirm: p95 < 500ms

---

### 8. E2E API-тесты (расширение Playwright) (ПРИОРИТЕТ: СРЕДНИЙ)

**У Hyperswitch:** Cypress тестирует API напрямую, не через UI.

**У нас:** Playwright тестирует UI дашборда. Добавить API E2E:

```
dashboard/e2e/
├── auth.spec.ts                     # ✅ есть
├── api/
│   ├── payment-flow.spec.ts         # create → confirm → capture через API
│   ├── refund-flow.spec.ts          # создание возврата через API
│   ├── connector-crud.spec.ts       # CRUD коннекторов
│   └── routing-rules.spec.ts        # правила роутинга через API
├── ui/
│   ├── payment-detail.spec.ts       # детали платежа в дашборде
│   ├── connector-setup.spec.ts      # визард подключения коннектора
│   └── routing-config.spec.ts       # настройка роутинга в UI
```

---

### 9. Тестовые утилиты и фабрики (ПРИОРИТЕТ: ВЫСОКИЙ)

**У Hyperswitch:** `ConnectorActions` trait — общие действия для всех коннекторных тестов.

**Что добавить нам:**

```php
// tests/Helpers/PaymentFactory.php
class PaymentFactory
{
    public static function createWithConnector(string $connector, array $overrides = []): Payment
    {
        // Создаёт мерчанта, коннектор, бизнес-профиль, платёж
    }

    public static function cardData(string $type = 'visa_success'): array
    {
        return match ($type) {
            'visa_success'    => ['number' => '4242424242424242', ...],
            'visa_3ds'        => ['number' => '4000000000003220', ...],
            'visa_decline'    => ['number' => '4000000000000002', ...],
            'mastercard'      => ['number' => '5555555555554444', ...],
        };
    }
}
```

```php
// tests/Helpers/ConnectorFixtures.php — записанные ответы коннекторов
class ConnectorFixtures
{
    public static function cloudpaymentsAuthorize(): array { ... }
    public static function cloudpaymentsCapture(): array { ... }
    public static function yookassaPayment(): array { ... }
}
```

---

## Приоритизированный план внедрения

### Фаза 1 — Основа (1-2 недели)
- [ ] `ConnectorTestCase` — базовый класс коннекторных тестов
- [ ] `PaymentFactory` + `ConnectorFixtures` — тестовые утилиты
- [ ] Расширить тесты жизненного цикла платежей (capture, void, 3DS)
- [ ] Фикстуры вебхуков для каждого коннектора

### Фаза 2 — Роутинг и изоляция (1-2 недели)
- [ ] Priority/Volume/Rule-based routing тесты
- [ ] Failover и retry тесты
- [ ] Тесты изоляции тенантов (cross-tenant access prevention)
- [ ] Идемпотентность платежей

### Фаза 3 — E2E и автоматизация (1 неделя)
- [ ] API E2E тесты через Playwright (`request` API, не UI)
- [ ] Расширить UI E2E тесты (визард коннектора, детали платежа)
- [ ] Добавить Playwright в CI (отдельный workflow)

### Фаза 4 — Нагрузка и мониторинг (по необходимости)
- [ ] k6 сценарии для основных эндпоинтов
- [ ] Docker Compose стек для нагрузочного тестирования
- [ ] Базовые пороги производительности в CI

---

## Ключевые идеи из Hyperswitch, которые стоит перенять

1. **Единый контракт для коннекторов** — каждый коннектор проходит одинаковый набор тестов. Это гарантирует, что новый коннектор не сломает систему.

2. **Mock + Live режимы** — CI гоняет моки, а перед релизом можно запустить тесты на реальных sandbox-средах коннекторов.

3. **Записанные фикстуры** — JSON-ответы коннекторов хранятся как фикстуры. При обновлении API коннектора фикстура обновляется, и все тесты сразу показывают, что изменилось.

4. **Матричное тестирование** — Cypress-тесты запускаются параллельно по батчам коннекторов (8 батчей × N коннекторов). Можно адаптировать для Pest с `--parallel`.

5. **Стейт-машина как отдельная сущность** — все допустимые и недопустимые переходы покрыты тестами, что предотвращает баги типа "платёж в статусе refunded внезапно стал captured".

6. **Тесты совместимости API** — Hyperswitch проверяет, что PR не ломает OpenAPI-спецификацию. Для нас это означает Scramble + тесты на обратную совместимость.
