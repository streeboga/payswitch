---
stepsCompleted: [1, 2, 3, 4, 5, 6, 7, 8]
inputDocuments:
  - '_bmad-output/planning-artifacts/prd.md'
  - 'docs/v1/compass_artifact_wf-1a861cee-48dc-4da1-8ff8-79927e317c2e_text_markdown.md'
workflowType: 'architecture'
project_name: 'payswitch'
user_name: 'K.mazurov'
date: '2026-03-18'
lastStep: 8
status: 'complete'
completedAt: '2026-03-18'
---

# Architecture Decision Document — payswitch

## Project Context Analysis

### Requirements Overview

**Functional Requirements:**
45 FR в 7 областях. Ядро — платёжный lifecycle (10 FR) с state machine, обеспечивающим корректные переходы статусов. Провизионирование мерчантов (8 FR) задаёт мультитенантную структуру. Webhook engine (5 FR) требует надёжной асинхронной доставки. Коннекторная интеграция (5 FR) через OmniPay определяет стратегию абстракции PSP.

**Non-Functional Requirements:**
17 NFR в 4 категориях. Критичные для архитектуры: performance (< 500мс payment creation), security (AES-256-CBC encryption, bcrypt keys, HTTPS-only, PCI DSS pass-through), scalability (stateless API, queue-based webhooks, partitioning by merchant_id), integration (Hyperswitch wire compatibility, OmniPay interface compliance).

**Scale & Complexity:**
- Primary domain: API Backend (Fintech payment orchestration)
- Complexity level: High
- Estimated architectural components: ~12-15

### Technical Constraints & Dependencies

- **Brownfield:** существующее Laravel 13 + React 19 приложение с Fortify auth
- **OmniPay-PHP:** все PSP-интеграции через OmniPay gateway interface
- **PostgreSQL:** для JSON columns (credentials, metadata, payment_methods_enabled)
- **Laravel Queue:** database driver для MVP, webhook delivery и state transitions
- **Hyperswitch API compatibility:** wire-level совместимость форматов запросов/ответов

### Cross-Cutting Concerns Identified

1. **Аутентификация и merchant resolution** — каждый запрос требует определения merchant из API key
2. **Шифрование** — credentials коннекторов, API key hashing, audit trail
3. **Error normalization** — PSP-специфичные ошибки нормализуются в единый формат
4. **Аудит-логирование** — все state transitions логируются
5. **ID generation** — единообразные префиксированные ID (pay_, cus_, ref_, pro_, mca_)
6. **Routing decisions** — выбор коннектора влияет на payment, refund, webhook routing

## Starter Template Evaluation

### Primary Technology Domain

**Brownfield Laravel 13 проект** — starter template не требуется. Проект уже инициализирован с Laravel 13 + React 19 + Inertia.js + Tailwind CSS 4 + Pest PHP.

### Existing Stack (из CLAUDE.md)

| Компонент | Технология | Версия |
|-----------|-----------|--------|
| PHP | PHP | 8.3+ |
| Framework | Laravel | 13 |
| Frontend | React | 19 (с React Compiler) |
| TypeScript | TypeScript | 5.7 |
| Build | Vite | 7 |
| CSS | Tailwind CSS | 4 |
| UI | Radix UI primitives | — |
| Auth | Laravel Fortify | — |
| Testing | Pest PHP | — |
| Code Style | Pint (PHP), ESLint + Prettier (TS) | — |
| Queue | Database driver | — |
| Bridge | Inertia.js | — |

### Architectural Decisions Provided by Existing Stack

- **Language & Runtime:** PHP 8.3+ с Laravel 13, TypeScript 5.7 для фронтенда
- **Auth:** Laravel Fortify (login, registration, 2FA, email verification, password reset) — уже реализован
- **Code Style:** Pint (preset: laravel) для PHP, ESLint + Prettier для TS/React
- **Testing:** Pest PHP с RefreshDatabase trait, in-memory SQLite для тестов
- **Build:** Vite 7 с SSR support

**Note:** Для MVP payswitch фронтенд (React/Inertia) не используется — это API-only расширение. Dashboard UI запланирован на Post-MVP.

## Core Architectural Decisions

### Decision Priority Analysis

**Critical Decisions (Block Implementation):**
1. Database — PostgreSQL вместо SQLite для production
2. API routing structure — отдельный route group для Hyperswitch-совместимого API
3. Authentication middleware — custom API key auth отдельно от Fortify session auth
4. Payment state machine — Enum-based с transition guards
5. Connector abstraction — OmniPay gateway interface wrapper

**Important Decisions (Shape Architecture):**
1. Layered architecture — Controller → Service → Repository
2. Encrypted fields strategy — Laravel Crypt для connector credentials
3. Webhook delivery — queue jobs с retry backoff
4. ID generation — custom ID generator с prefix strategy

**Deferred Decisions (Post-MVP):**
1. Smart routing algorithms
2. Caching layer (Redis)
3. Rate limiting strategy
4. API versioning approach

### Data Architecture

**Database:** PostgreSQL 16+
- JSON/JSONB columns для: `connector_account_details`, `payment_methods_enabled`, `metadata`
- Encrypted text columns для: `connector_account_details` (application-level encryption)
- UUID primary keys для всех таблиц

**Schema Design:**

```sql
-- Иерархия тенантов
organizations (id, org_id, name, metadata, created_at, updated_at)
merchant_accounts (id, merchant_id, org_id, publishable_key, metadata, created_at, updated_at)
business_profiles (id, profile_id, merchant_id, webhook_url, payment_response_hash_key, metadata, created_at, updated_at)

-- Аутентификация
api_keys (id, merchant_id, key_hash, key_prefix, name, expires_at, revoked_at, created_at, updated_at)

-- Коннекторы
merchant_connector_accounts (id, mca_id, merchant_id, profile_id, connector_name, connector_type, connector_account_details, payment_methods_enabled, test_mode, disabled, created_at, updated_at)

-- Платёжное ядро
payment_intents (id, payment_id, merchant_id, profile_id, amount, net_amount, amount_capturable, amount_received, currency, status, client_secret, capture_method, authentication_type, customer_id, return_url, description, metadata, connector, attempt_count, session_expiry, created_at, updated_at, expires_on)
payment_attempts (id, attempt_id, payment_id, merchant_id, connector, connector_transaction_id, status, amount, error_code, error_message, created_at, updated_at)

-- Рефанды
refunds (id, refund_id, payment_id, merchant_id, profile_id, amount, currency, status, reason, connector, connector_refund_id, error_code, error_message, metadata, created_at, updated_at)

-- Клиенты
customers (id, customer_id, merchant_id, name, email, phone, phone_country_code, description, metadata, default_payment_method_id, created_at, updated_at)

-- Webhook-и
webhook_events (id, event_id, event_type, merchant_id, profile_id, payment_id, content, delivered, delivery_attempts, next_retry_at, last_error, created_at, updated_at)

-- Аудит
payment_audit_log (id, payment_id, merchant_id, action, previous_status, new_status, actor, metadata, created_at)
```

**Migration approach:** Laravel migrations, sequential, one migration per table.

### Authentication & Security

**API Key Authentication (custom middleware):**
- Заголовок `api-key` для всех запросов
- Admin API Key: конфигурационная константа из `.env` (`PAYSWITCH_ADMIN_API_KEY`)
- Secret API Key: key_prefix хранится в plaintext для lookup, key_hash (bcrypt) для верификации
- Publishable Key: полный ключ хранится в plaintext (ограниченный scope)
- Merchant ID определяется из API key через lookup в `api_keys` таблице

**Middleware stack для API:**
1. `ForceHttps` — отклоняет HTTP в production
2. `ResolveApiKey` — парсит `api-key` заголовок, определяет тип ключа
3. `AuthenticateApiKey` — верифицирует ключ, резолвит merchant
4. `SetMerchantContext` — устанавливает merchant context для request lifecycle

**Encryption:**
- `Crypt::encryptString()` / `Crypt::decryptString()` для connector credentials
- `Hash::make()` / `Hash::check()` (bcrypt) для secret API keys
- `hash_hmac('sha512', ...)` для webhook signatures

### API & Communication Patterns

**API Design:** REST, Hyperswitch-compatible

**Route structure:**
```php
// routes/api.php
Route::prefix('api/v1')->group(function () {
    // Admin API (requires Admin API Key)
    Route::middleware('auth.admin_api_key')->group(function () {
        Route::post('/organization', ...);
        Route::post('/accounts', ...);
        Route::get('/accounts/{id}', ...);
        Route::post('/profiles', ...);
        Route::get('/profiles/{id}', ...);
        Route::post('/api_keys/{merchant_id}', ...);
        Route::post('/account/{merchant_id}/connectors', ...);
        Route::get('/account/{merchant_id}/connectors', ...);
    });

    // Merchant API (requires Secret API Key)
    Route::middleware('auth.secret_api_key')->group(function () {
        Route::post('/payments', ...);
        Route::get('/payments/{id}', ...);
        Route::post('/payments/{id}/confirm', ...);
        Route::post('/payments/{id}/capture', ...);
        Route::post('/payments/{id}/cancel', ...);
        Route::post('/refunds', ...);
        Route::get('/refunds/{id}', ...);
        Route::post('/customers', ...);
        Route::get('/customers/{id}', ...);
        Route::post('/customers/{id}', ...);
        Route::delete('/customers/{id}', ...);
    });

    // Webhook receiver (from PSP)
    Route::post('/webhooks/{merchant_id}/{mca_id}', ...);
});
```

**Error response format:**
```json
{
    "error": {
        "type": "invalid_request_error",
        "code": "payment_not_found",
        "message": "Payment with id pay_xxx not found"
    }
}
```

**API response:** прямой JSON без обёртки (Hyperswitch-compatible). Успешные ответы возвращают объект ресурса напрямую.

### Infrastructure & Deployment

**Hosting:** Self-hosted (VPS/dedicated server), Docker optional
**Web server:** Nginx + PHP-FPM
**Database:** PostgreSQL 16+ (отдельный от SQLite для тестов)
**Queue worker:** `php artisan queue:work` (supervisor/systemd)
**Logging:** Laravel Log (daily files), structured JSON в production
**Monitoring:** Laravel Telescope для development, basic health endpoint для production

## Implementation Patterns & Consistency Rules

### Naming Patterns

**Database Naming:**
- Tables: snake_case, plural (`payment_intents`, `merchant_accounts`)
- Columns: snake_case (`payment_id`, `merchant_id`, `created_at`)
- Foreign keys: `{related_table_singular}_id` (`merchant_id`, `profile_id`)
- Indexes: `{table}_{columns}_index` (`payment_intents_merchant_id_index`)
- Unique: `{table}_{columns}_unique` (`api_keys_key_prefix_unique`)

**API Naming (Hyperswitch-compatible):**
- Endpoints: plural nouns (`/payments`, `/refunds`, `/customers`)
- Actions: `/{resource}/{id}/{action}` (`/payments/{id}/confirm`)
- Query params: snake_case (`customer_id`, `payment_method`)
- JSON fields: snake_case (`payment_id`, `client_secret`, `amount_capturable`)
- Headers: kebab-case (`api-key`, `x-webhook-signature-512`)

**Code Naming (PHP/Laravel):**
- Classes: PascalCase (`PaymentIntent`, `CreatePaymentAction`)
- Methods: camelCase (`createPayment`, `resolveConnector`)
- Variables: camelCase (`$paymentIntent`, `$merchantId`)
- Constants: UPPER_SNAKE_CASE (`PAYMENT_STATUS_SUCCEEDED`)
- Config keys: snake_case (`payswitch.admin_api_key`)
- Enums: PascalCase с UPPER_SNAKE_CASE values (`PaymentStatus::REQUIRES_PAYMENT_METHOD`)

### Structure Patterns

**Layered Architecture:**
```
Request → Middleware → Controller → Action → Service → Repository → Model
                                                    → OmniPay Gateway
```

- **Controllers:** тонкие, только валидация запроса и вызов Action
- **Actions:** бизнес-логика одной операции (CreatePaymentAction, ConfirmPaymentAction)
- **Services:** координация между компонентами (PaymentService, WebhookService)
- **Repositories:** data access через Eloquent (PaymentIntentRepository)
- **Models:** Eloquent models с casts, scopes, relationships

**Test Structure:**
- `tests/Feature/Api/Payments/` — API endpoint тесты
- `tests/Feature/Api/Refunds/`
- `tests/Feature/Api/Customers/`
- `tests/Feature/Api/Admin/`
- `tests/Unit/Actions/` — unit тесты для Action классов
- `tests/Unit/Services/` — unit тесты для сервисов
- `tests/Unit/StateMachine/` — тесты переходов статусов

### Format Patterns

**Payment Status Enum:**
```php
enum PaymentStatus: string
{
    case REQUIRES_PAYMENT_METHOD = 'requires_payment_method';
    case REQUIRES_CONFIRMATION = 'requires_confirmation';
    case REQUIRES_CUSTOMER_ACTION = 'requires_customer_action';
    case REQUIRES_MERCHANT_ACTION = 'requires_merchant_action';
    case PROCESSING = 'processing';
    case REQUIRES_CAPTURE = 'requires_capture';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
    case PARTIALLY_CAPTURED = 'partially_captured';
    case PARTIALLY_CAPTURED_AND_CAPTURABLE = 'partially_captured_and_capturable';
}
```

**ID Generation:**
```php
// Utility class: App\Support\IdGenerator
IdGenerator::paymentId();    // "pay_" + 26 chars (Str::random)
IdGenerator::customerId();   // "cus_" + 26 chars
IdGenerator::refundId();     // "ref_" + 26 chars
IdGenerator::profileId();    // "pro_" + 26 chars
IdGenerator::mcaId();        // "mca_" + 26 chars
IdGenerator::merchantId();   // "merchant_" + 20 chars
IdGenerator::clientSecret($paymentId); // "{paymentId}_secret_{random}"
IdGenerator::apiKeyPrefix($env);       // "snd_" or "prod_" + 26 chars
```

**Date/Time:** ISO 8601 strings в JSON (`"2026-03-18T10:00:00Z"`), Carbon internally.

### Communication Patterns

**Events (Laravel Events):**
- Naming: `PaymentStatusChanged`, `WebhookDeliveryFailed`, `ConnectorResponseReceived`
- Payload: полный model object + context metadata
- Listeners: `SendWebhookNotification`, `LogPaymentAudit`, `UpdatePaymentAttempt`

**Queue Jobs:**
- `DeliverWebhookJob` — доставка webhook с retry
- `ExpireStalePaymentsJob` — scheduled job для expired платежей
- Все jobs implement `ShouldQueue`, используют `database` connection

### Process Patterns

**Error Handling:**
- PSP errors нормализуются в `ConnectorError` value object
- API errors возвращаются через `PaymentException` hierarchy
- Global exception handler маппит exceptions на Hyperswitch-compatible error responses
- Logging: `Log::error()` для серверных ошибок, `Log::info()` для бизнес-событий

**State Machine Enforcement:**
```php
// App\StateMachine\PaymentStateMachine
class PaymentStateMachine
{
    public static function canTransition(PaymentStatus $from, PaymentStatus $to): bool;
    public static function transition(PaymentIntent $payment, PaymentStatus $to): void;
    public static function allowedTransitions(PaymentStatus $from): array;
}
```
Каждый transition вызывает `PaymentStatusChanged` event → triggers audit log + webhook.

### Enforcement Guidelines

**All AI Agents MUST:**
- Использовать Action классы для бизнес-операций (никакой логики в контроллерах)
- Проверять state machine transitions через `PaymentStateMachine::canTransition()` перед изменением статуса
- Генерировать ID через `IdGenerator` (никаких ручных ID)
- Шифровать connector credentials через `Crypt::encryptString()`
- Логировать все state transitions через `PaymentStatusChanged` event
- Возвращать ошибки в Hyperswitch-compatible формате через `PaymentException`
- Писать Pest тесты для каждого API endpoint и state transition

## Project Structure & Boundaries

### Complete Project Directory Structure

```
payswitch/
├── app/
│   ├── Actions/
│   │   ├── Fortify/                    # Existing auth actions
│   │   └── Payswitch/
│   │       ├── Payments/
│   │       │   ├── CreatePaymentAction.php
│   │       │   ├── ConfirmPaymentAction.php
│   │       │   ├── CapturePaymentAction.php
│   │       │   └── CancelPaymentAction.php
│   │       ├── Refunds/
│   │       │   └── CreateRefundAction.php
│   │       ├── Customers/
│   │       │   ├── CreateCustomerAction.php
│   │       │   ├── UpdateCustomerAction.php
│   │       │   └── DeleteCustomerAction.php
│   │       ├── Admin/
│   │       │   ├── CreateOrganizationAction.php
│   │       │   ├── CreateMerchantAccountAction.php
│   │       │   ├── CreateBusinessProfileAction.php
│   │       │   ├── CreateApiKeyAction.php
│   │       │   ├── RevokeApiKeyAction.php
│   │       │   ├── CreateConnectorAction.php
│   │       │   └── UpdateConnectorAction.php
│   │       └── Webhooks/
│   │           └── ProcessIncomingWebhookAction.php
│   ├── Connectors/
│   │   ├── ConnectorInterface.php       # Adapter interface
│   │   ├── AbstractConnector.php        # Base OmniPay wrapper
│   │   ├── StripeConnector.php
│   │   ├── YooKassaConnector.php
│   │   └── ConnectorFactory.php         # Resolves connector by name
│   ├── Enums/
│   │   ├── PaymentStatus.php
│   │   ├── RefundStatus.php
│   │   ├── CaptureMethod.php
│   │   ├── AuthenticationType.php
│   │   ├── ConnectorType.php
│   │   ├── ApiKeyType.php
│   │   └── WebhookEventType.php
│   ├── Events/
│   │   ├── PaymentStatusChanged.php
│   │   ├── RefundStatusChanged.php
│   │   └── WebhookDeliveryFailed.php
│   ├── Exceptions/
│   │   ├── PaymentException.php
│   │   ├── ConnectorException.php
│   │   ├── InvalidStateTransitionException.php
│   │   └── AuthenticationException.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Settings/               # Existing settings controllers
│   │   │   └── Api/
│   │   │       ├── PaymentController.php
│   │   │       ├── RefundController.php
│   │   │       ├── CustomerController.php
│   │   │       ├── Admin/
│   │   │       │   ├── OrganizationController.php
│   │   │       │   ├── MerchantAccountController.php
│   │   │       │   ├── BusinessProfileController.php
│   │   │       │   ├── ApiKeyController.php
│   │   │       │   └── ConnectorController.php
│   │   │       └── WebhookReceiverController.php
│   │   ├── Middleware/
│   │   │   ├── HandleInertiaRequests.php  # Existing
│   │   │   ├── HandleAppearance.php       # Existing
│   │   │   ├── ResolveApiKey.php
│   │   │   ├── AuthenticateAdminApiKey.php
│   │   │   ├── AuthenticateSecretApiKey.php
│   │   │   └── SetMerchantContext.php
│   │   └── Requests/
│   │       └── Api/
│   │           ├── CreatePaymentRequest.php
│   │           ├── ConfirmPaymentRequest.php
│   │           ├── CapturePaymentRequest.php
│   │           ├── CreateRefundRequest.php
│   │           ├── CreateCustomerRequest.php
│   │           ├── UpdateCustomerRequest.php
│   │           └── Admin/
│   │               ├── CreateOrganizationRequest.php
│   │               ├── CreateMerchantAccountRequest.php
│   │               ├── CreateBusinessProfileRequest.php
│   │               ├── CreateApiKeyRequest.php
│   │               └── CreateConnectorRequest.php
│   ├── Jobs/
│   │   ├── DeliverWebhookJob.php
│   │   └── ExpireStalePaymentsJob.php
│   ├── Listeners/
│   │   ├── SendWebhookNotification.php
│   │   └── LogPaymentAudit.php
│   ├── Models/
│   │   ├── Organization.php
│   │   ├── MerchantAccount.php
│   │   ├── BusinessProfile.php
│   │   ├── ApiKey.php
│   │   ├── MerchantConnectorAccount.php
│   │   ├── PaymentIntent.php
│   │   ├── PaymentAttempt.php
│   │   ├── Refund.php
│   │   ├── Customer.php
│   │   ├── WebhookEvent.php
│   │   └── PaymentAuditLog.php
│   ├── Repositories/
│   │   ├── PaymentIntentRepository.php
│   │   ├── RefundRepository.php
│   │   ├── CustomerRepository.php
│   │   ├── MerchantRepository.php
│   │   └── WebhookEventRepository.php
│   ├── Services/
│   │   ├── PaymentService.php
│   │   ├── RefundService.php
│   │   ├── RoutingService.php
│   │   ├── WebhookService.php
│   │   ├── ApiKeyService.php
│   │   └── ConnectorService.php
│   ├── StateMachine/
│   │   └── PaymentStateMachine.php
│   └── Support/
│       ├── IdGenerator.php
│       ├── WebhookSigner.php
│       └── ConnectorErrorNormalizer.php
├── config/
│   └── payswitch.php                    # Admin API key, ID prefixes, webhook retry config
├── database/
│   └── migrations/
│       ├── xxxx_create_organizations_table.php
│       ├── xxxx_create_merchant_accounts_table.php
│       ├── xxxx_create_business_profiles_table.php
│       ├── xxxx_create_api_keys_table.php
│       ├── xxxx_create_merchant_connector_accounts_table.php
│       ├── xxxx_create_payment_intents_table.php
│       ├── xxxx_create_payment_attempts_table.php
│       ├── xxxx_create_refunds_table.php
│       ├── xxxx_create_customers_table.php
│       ├── xxxx_create_webhook_events_table.php
│       └── xxxx_create_payment_audit_log_table.php
├── routes/
│   ├── web.php                          # Existing web routes
│   ├── settings.php                     # Existing settings routes
│   └── api.php                          # Payswitch API routes
├── tests/
│   ├── Feature/
│   │   ├── Auth/                        # Existing auth tests
│   │   ├── Settings/                    # Existing settings tests
│   │   └── Api/
│   │       ├── Payments/
│   │       │   ├── CreatePaymentTest.php
│   │       │   ├── ConfirmPaymentTest.php
│   │       │   ├── CapturePaymentTest.php
│   │       │   ├── CancelPaymentTest.php
│   │       │   └── GetPaymentTest.php
│   │       ├── Refunds/
│   │       │   ├── CreateRefundTest.php
│   │       │   └── GetRefundTest.php
│   │       ├── Customers/
│   │       │   ├── CreateCustomerTest.php
│   │       │   ├── UpdateCustomerTest.php
│   │       │   ├── DeleteCustomerTest.php
│   │       │   └── GetCustomerTest.php
│   │       ├── Admin/
│   │       │   ├── OrganizationTest.php
│   │       │   ├── MerchantAccountTest.php
│   │       │   ├── BusinessProfileTest.php
│   │       │   ├── ApiKeyTest.php
│   │       │   └── ConnectorTest.php
│   │       ├── Webhooks/
│   │       │   └── WebhookReceiverTest.php
│   │       └── Auth/
│   │           ├── AdminApiKeyAuthTest.php
│   │           ├── SecretApiKeyAuthTest.php
│   │           └── PublishableKeyAuthTest.php
│   └── Unit/
│       ├── StateMachine/
│       │   └── PaymentStateMachineTest.php
│       ├── Actions/
│       │   └── ...
│       ├── Support/
│       │   ├── IdGeneratorTest.php
│       │   └── WebhookSignerTest.php
│       └── Connectors/
│           ├── StripeConnectorTest.php
│           └── YooKassaConnectorTest.php
```

### Architectural Boundaries

**API Boundary:**
- Все Payswitch API endpoints под `/api/v1/` prefix
- Отделены от существующих web routes (Inertia/Fortify)
- Custom API key auth middleware — не пересекается с Fortify session auth

**Connector Boundary:**
- `ConnectorInterface` определяет контракт для всех PSP-интеграций
- `AbstractConnector` реализует общую OmniPay логику
- Каждый конкретный connector знает только о своём PSP
- `ConnectorFactory::resolve($name)` создаёт нужный connector

**Data Boundary:**
- Repositories инкапсулируют все Eloquent запросы
- Controllers никогда не обращаются к моделям напрямую
- Encrypted fields прозрачны для бизнес-логики (decrypt в accessor, encrypt в mutator)

### Requirements to Structure Mapping

| FR Area | Controllers | Actions | Services | Models |
|---------|------------|---------|----------|--------|
| Аутентификация (FR1-5) | — | — | ApiKeyService | ApiKey, MerchantAccount |
| Платежи (FR6-15) | PaymentController | Create/Confirm/Capture/Cancel | PaymentService | PaymentIntent, PaymentAttempt |
| Рефанды (FR16-19) | RefundController | CreateRefundAction | RefundService | Refund |
| Клиенты (FR20-24) | CustomerController | Create/Update/Delete | — | Customer |
| Провизионирование (FR25-32) | Admin/* Controllers | Admin/* Actions | — | Organization, MerchantAccount, BusinessProfile, ApiKey, MCA |
| Маршрутизация (FR33-35) | — | — | RoutingService | MCA |
| Webhook-и (FR36-40) | WebhookReceiverController | ProcessIncoming | WebhookService | WebhookEvent |
| Коннекторы (FR41-45) | — | — | ConnectorService | MCA + Connectors/* |

### Data Flow

```
Client Request
    → Nginx
    → Laravel Router (api.php)
    → API Key Middleware (resolve merchant)
    → Controller (validate request)
    → Action (business logic)
    → Service (coordination)
    → Repository (data access) + ConnectorService (PSP call via OmniPay)
    → StateMachine (enforce transitions)
    → Event (PaymentStatusChanged)
    → Listener: LogPaymentAudit + SendWebhookNotification
    → Queue: DeliverWebhookJob
    → Response (Hyperswitch-compatible JSON)
```

## Architecture Validation Results

### Coherence Validation ✅

**Decision Compatibility:** Все технологические решения совместимы — Laravel 13 + PostgreSQL + OmniPay + Queue driver — стандартный Laravel stack без конфликтов.

**Pattern Consistency:** Naming conventions (snake_case для DB/API, camelCase для PHP code) следуют Laravel conventions. Hyperswitch snake_case JSON fields совпадают с Laravel default JSON serialization.

**Structure Alignment:** Project structure следует Laravel conventions с добавлением payswitch-specific directories (Connectors, StateMachine, Support). Не нарушает существующую структуру brownfield-приложения.

### Requirements Coverage Validation ✅

**Functional Requirements:** Все 45 FR маппятся на конкретные архитектурные компоненты (см. Requirements to Structure Mapping).

**Non-Functional Requirements:**
- Performance (< 500мс): stateless API, PostgreSQL with proper indexes
- Security: 4-layer middleware stack, encrypted credentials, bcrypt keys
- Scalability: stateless design, queue-based webhooks, partitionable schema
- Integration: OmniPay abstraction layer, Hyperswitch-compatible formats

### Implementation Readiness ✅

**Confidence Level:** High

**Key Strengths:**
- Чёткая layered architecture с separation of concerns
- State machine как центральный enforcement mechanism
- OmniPay abstraction позволяет добавлять коннекторы без изменения ядра
- Brownfield-совместимость — не ломает существующее приложение
- Полная schema с 11 таблицами покрывающими все FR

**Areas for Future Enhancement:**
- Redis caching для API key lookup (Post-MVP)
- Rate limiting middleware (Post-MVP)
- API versioning strategy (при необходимости breaking changes)
- Telescope/Horizon для production monitoring

### Implementation Handoff

**AI Agent Guidelines:**
- Follow all architectural decisions exactly as documented
- Use implementation patterns consistently across all components
- Respect project structure and boundaries
- Refer to this document for all architectural questions

**First Implementation Priority:**
1. Database migrations (schema setup)
2. Models with relationships and casts
3. Enums (PaymentStatus, etc.)
4. Support classes (IdGenerator, WebhookSigner)
5. StateMachine
6. Middleware stack
7. Admin API (provisioning)
8. Payment lifecycle endpoints
9. Refunds and Customers
10. Webhook engine
11. Connector integrations (Stripe, YooKassa)
