# Code Review Report — Payswitch Backend API

**Дата:** 2026-03-18
**Scope:** Все слои backend API (Controllers, Services, Repositories, Models, Resources, Enums, QueryBuilders, Routes, Tests)

---

## 1. Архитектура (Controller → Service → Repository → QueryBuilder): ⚠️

**✅ Что хорошо:**
- Контроллеры преимущественно инжектируют Service (28 из 34)
- Данные основных сущностей передаются через DTO (payments, refunds, connectors, customers, merchants)
- Dependency Injection через конструктор
- Интерфейсы для всех 8 репозиториев
- Нет циклических зависимостей

**❌ Критические проблемы:**

1. **2 контроллера инжектируют Repository напрямую, минуя Service:**
   - `DashboardRoutingRuleController` → `RoutingRuleRepositoryInterface`
   - `DashboardWebhookEventController` → `WebhookEventRepositoryInterface`

2. **7 сервисов обращаются к БД напрямую (Model::query(), DB::table()), минуя Repository:**
   - `NotificationService` → `AppNotification::` напрямую
   - `UserSettingsService` → `UserPreference::` напрямую
   - `ConnectorHealthService` → `DB::table()` + модели напрямую
   - `AuditLogService` → `Activity::` напрямую
   - `UserRoleService` → `UserRole::` напрямую
   - `DisputeService` → `Dispute::`, `DisputeEvidence::` напрямую
   - `SavedFilterService` → `SavedFilter::` напрямую
   - `EventLogService` → `DB::table()` напрямую

3. **TwoFactorChallengeController** содержит бизнес-логику (36 строк с if/elseif) и обращается к `User::findOrFail()` напрямую

**⚠️ Требует улучшения:**

4. **Толстые контроллеры** (> 15 строк на метод):
   - `AuditLogController::index()` — 45 строк (ручной маппинг коллекции + пагинация)
   - `EventLogController::index()` — 39 строк (маппинг + пагинация)
   - `DisputeController::submitEvidence()` — 32 строк (file upload + валидация)
   - `DashboardPaymentController::export()` — 29 строк (CSV streaming)
   - `UserSettingsController::update()` — 35 строк (валидация + response building)

5. **Inline валидация** (`$request->validate()`) в контроллерах вместо FormRequest:
   - `DashboardCustomerController`, `TestPaymentController`, `DashboardConnectorController`, `UserRoleController`, `DashboardBusinessProfileController`, `SavedFilterController`, `DisputeController`

---

## 2. Качество кода: ⚠️

**✅ Что хорошо:**
- `declare(strict_types=1)` — 100% сервисов, репозиториев, моделей, enums, builders
- `final readonly` — 100% сервисов, 100% репозиториев
- Все параметры и return types типизированы в сервисах/репозиториях
- `final` на всех QueryBuilder'ах

**⚠️ Требует улучшения:**
- Контроллеры **не используют `final`** (ни один)
- API Resources **не используют `final`** (ни один)

---

## 3. Enums: ⚠️

**✅ Что хорошо:**
- 7 PHP Enums определены (ConnectorName, PaymentAttemptStatus, RoutingRuleType, WebhookEventType, DisputeType, DisputeStatus, UserRole)
- Модели используют cast к Enum (Dispute → DisputeType, DisputeStatus; UserRole → UserRoleEnum)
- Все enum'ы backed by string

**❌ Критические проблемы:**

1. **Ни один Enum не реализует HasLabel, HasColor, HasIcon** — отсутствуют методы getLabel(), getColor(), getIcon()
2. **DisputeStatus не имеет `canTransitionTo()` и `isTerminal()`** — статусные enum'ы должны иметь state machine логику
3. **Magic string в WebhookEventQueryBuilder** — `->where('status', 'pending')` вместо Enum

**⚠️ Требует улучшения:**
4. Нет CacheKey Enum — кеш-ключи задаются как строки
5. Нет CacheTtl Enum — TTL как magic numbers

---

## 4. QueryBuilder: ✅

**✅ Что хорошо:**
- 5 QueryBuilder классов (Customer, MerchantConnector, PaymentIntent, Refund, WebhookEvent)
- Модели **не содержат** `scopeXxx()` методов (ни одного!)
- Все Builder'ы возвращают `$this` для chaining
- Валидация разрешённых колонок в `sortBy()` (PaymentIntentQueryBuilder, CustomerQueryBuilder, RefundQueryBuilder)
- Экранирование в `search()` методах (RefundQueryBuilder, PaymentIntentQueryBuilder)
- `final` на всех Builder'ах

**⚠️ Требует улучшения:**
- `WebhookEventQueryBuilder` создан, но **не используется** в `WebhookEventRepository`
- `PaymentMethodRepository` и `RoutingRuleRepository` работают с Eloquent напрямую (без Builder)
- `PaymentIntentQueryBuilder::withStatus()` принимает `string`, а не Enum

---

## 5. Service Layer: ⚠️

**✅ Что хорошо:**
- 100% сервисов: `final readonly class`, `declare(strict_types=1)`
- Транзакции в ключевых операциях: PaymentService, RefundService, WebhookReceiverService, PaymentMethodService
- События диспатчатся в сервисе (PaymentStatusChanged)
- Jobs для тяжёлых операций (DeliverWebhookJob)
- Сервис-к-сервису допустимо: PaymentService → RoutingService, TestPaymentService → PaymentService

**❌ Критические проблемы:**
- **7 сервисов обращаются к БД напрямую** (см. секцию Архитектура п.2)

**⚠️ Требует улучшения:**
- Не все DTO используются — dashboard-сервисы часто принимают массивы (NotificationService, UserSettingsService, SavedFilterService, DisputeService)

---

## 6. Repository: ✅

**✅ Что хорошо:**
- 8 интерфейсов в `Contracts/`, 8 реализаций в `Eloquent/`
- Все: `final readonly class`, `declare(strict_types=1)`
- Полные return types
- Зарегистрированы в `RepositoryServiceProvider`
- Используют QueryBuilder для сложных запросов (CustomerRepository, PaymentIntentRepository, RefundRepository)

**⚠️ Требует улучшения:**
- `AnalyticsRepository` содержит сложную бизнес-логику (SQL aggregations, success rates, funnels) — стоит вынести расчётную логику в Service
- Для 7 сервисов с прямым доступом к моделям **нет репозиториев** (Notification, UserPreference, Dispute, SavedFilter, UserRole, Activity, EventLog)

---

## 7. Безопасность: ✅

**✅ Что хорошо:**
- Sanctum для SPA аутентификации
- Rate limiting на login (5/min), two-factor (5/min), API (динамический)
- CORS настроен через `config/cors.php`
- `$fillable` определён на моделях
- Sensitive данные скрыты: User `#[Hidden(['password', 'two_factor_secret', ...])]`
- API key аутентификация через SHA256 hash
- `Model::preventLazyLoading()` в non-production
- `DB::prohibitDestructiveCommands()` в production
- JSON:API error format для всех исключений в `bootstrap/app.php`

**⚠️ Требует улучшения:**
- Inline `$request->validate()` вместо FormRequest (нет централизованной санитизации через `prepareForValidation()`)
- Policies для авторизации не обнаружены — IDOR-защита через middleware `resolve.merchant`, но нет per-entity Policies
- File upload в `DisputeController::submitEvidence()` — нужна проверка MIME types

---

## 8. Производительность: ✅

**✅ Что хорошо:**
- `Model::preventLazyLoading()` в AppServiceProvider
- Eager loading через `->with()` в репозиториях
- Chunking: `cursor()` в PaymentIntentQueryBuilder для экспорта
- Валидация разрешённых колонок для сортировки

**⚠️ Требует улучшения:**
- Некоторые контроллеры загружают полные коллекции в память (AuditLogController::index() делает `->map()` на результате paginate)

---

## 9. Публичные ключи: ✅

**✅ Что хорошо:**
- Модели генерируют key через prefix + `Str::ulid()` в `booted()` (AppNotification: `ntf_`, Dispute: `dsp_`)
- `getRouteKeyName() → 'key'` (Dispute)
- API Resources используют key как id: `toId()` → `$this->key ?? (string) $this->id`
- В URL — key, не числовой id

**⚠️ Требует улучшения:**
- `getRouteKeyName()` определён только в `Dispute` — другие локальные модели (AppNotification, SavedFilter, UserPreference, UserRole) не имеют этого метода

---

## 10. JSON:API Compliance: ⚠️

**✅ Что хорошо:**
- Ресурсы имеют `toId()`, `toType()`, `toAttributes()`, `toRelationships()`, `toLinks()`
- `toId()` возвращает key
- `toType()` — множественное число ('customers', 'payments', 'api-keys')
- Обновление через PATCH (не PUT) — все маршруты корректны
- Удаление → 204 No Content
- Content-Type: `application/vnd.api+json` через middleware `ForceJsonApiContentType`
- Ошибки в формате `{errors: [{status, code, title, detail, source?}]}`
- Фильтрация, сортировка работают через query parameters

**❌ Критические проблемы:**

1. **Resources расширяют кастомный `JsonApiResource`**, а не `TiMacDonald\JsonApi\JsonApiResource`. Зависимость `timacdonald/json-api` не используется по назначению.

2. **Ручной JSON в нескольких контроллерах** вместо Resource classes:
   - `AuditLogController::index()` — ручная сборка `response()->json([...])`
   - `EventLogController::index()` — ручная сборка
   - `ConnectorHealthController` — ручная сборка
   - `UserSettingsController` — ручная сборка
   - `UserRoleController::index()` — ручная сборка через `->map()`
   - `SavedFilterController::index()` — ручная сборка через `->map()`
   - `AnalyticsController` — custom `jsonApiResponse()` helper

3. **Нет Location header** при создании ресурсов (201)

---

## 11. API Documentation (Scramble): ❌

**✅ Что хорошо:**
- Dashboard контроллеры имеют `#[Group]`, `#[Response]`, `#[PathParameter]`, `#[QueryParameter]`

**❌ Критические проблемы:**

1. **Все API V1 контроллеры (5 файлов) имеют только `#[Group]`** — полностью отсутствуют:
   - PHPDoc summary и description
   - `#[PathParameter]`
   - `#[Response]`
   - `#[QueryParameter]`
   - Затронуты: `PaymentController`, `CustomerController`, `RefundController`, `PaymentMethodController`, `WebhookReceiverController`

2. **Все API V1 Admin контроллеры (6 файлов) имеют только `#[Group]`** — аналогично:
   - `MerchantAccountController`, `BusinessProfileController`, `OrganizationController`, `ApiKeyController`, `ConnectorController`, `RoutingRuleController`

3. **Auth контроллеры** — полное отсутствие атрибутов:
   - `LoginController`, `TwoFactorChallengeController`, `UserController`

**Итого: 14 из 34 контроллеров без документации = ~40% API недокументировано**

---

## 12. Тестирование: ⚠️

**✅ Что хорошо:**
- Pest PHP с `RefreshDatabase`
- Хорошая структура: Feature/Auth, Feature/Dashboard (18 тестов), Feature/Api (13 тестов), Feature/Services, Feature/EdgeCases
- JSON:API envelope в тестах (`data.type`, `data.attributes`)
- CRUD + авторизация + валидация покрыты
- PATCH для обновлений
- 204 для удалений

**⚠️ Требует улучшения:**
- `TestCase.php` — пустой (нет базового `ApiTestCase` с хелперами)
- Нет проверки `assertJsonStructure(['errors' => [['status', 'title', 'detail']]])` в тестах ошибок
- Отсутствуют `Event::fake()`, `Queue::fake()` в тестах side-effects
- Нет `Http::preventStrayRequests()` для внешних API

---

## 13. Laravel 13 Compliance: ✅

**✅ Что хорошо:**
- Middleware в `bootstrap/app.php` (не в Kernel.php)
- `AppServiceProvider` + `RepositoryServiceProvider`
- Providers в `bootstrap/providers.php`
- Rate limiting в `AppServiceProvider::boot()`
- `casts()` метод во всех моделях (не `$casts` property)
- `CarbonImmutable` для дат
- `preventLazyLoading()`, `prohibitDestructiveCommands()`

---

## Итоговая таблица

| Секция | Статус |
|--------|--------|
| Архитектура | ⚠️ |
| Качество кода | ⚠️ |
| Enums | ⚠️ |
| QueryBuilder | ✅ |
| Service Layer | ⚠️ |
| Repository | ✅ |
| Безопасность | ✅ |
| Производительность | ✅ |
| Публичные ключи | ✅ |
| JSON:API Compliance | ⚠️ |
| API Documentation | ❌ |
| Тестирование | ⚠️ |
| Laravel 13 | ✅ |

### Оценка: 6.5/10

### Критические проблемы: 4

1. **7 сервисов обращаются к БД напрямую**, минуя Repository
2. **14 контроллеров без Scramble-документации** (#[PathParameter], #[Response], PHPDoc)
3. **Resources не используют `timacdonald/json-api`** — кастомный JsonApiResource
4. **7 контроллеров строят JSON:API ответы вручную** вместо Resource classes

### Приоритетные исправления (топ-5):

1. **Scramble-документация для API V1 и Admin контроллеров** — добавить PHPDoc, `#[PathParameter]`, `#[Response]`, `#[QueryParameter]` во все 14 контроллеров
2. **Создать репозитории** для Notification, UserPreference, Dispute, SavedFilter, UserRole, Activity, EventLog — убрать прямой доступ к моделям из сервисов
3. **Создать Resource classes** для AuditLog, EventLog, ConnectorHealth, UserSettings, UserRole, SavedFilter, Analytics — убрать ручной JSON из контроллеров
4. **Вынести inline `$request->validate()`** в FormRequest классы с `prepareForValidation()` и `toDto()`
5. **Добавить HasLabel/HasColor/HasIcon** на Enums + `canTransitionTo()`/`isTerminal()` на статусные enum'ы (DisputeStatus)
