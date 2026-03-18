# Техдолг: Рефакторинг dashboard контроллеров к полной layered architecture

**Дата:** 2026-03-18
**Приоритет:** Medium
**Оценка:** 1 спринт

## Контекст

Code review по чеклисту из `references/code-review.md` выявил архитектурные отклонения в dashboard-слое. Все endpoints работают, тесты проходят (125 dashboard + 12 connector), API отвечает корректно. Нарушения не критические — код функционален, но не соответствует target architecture.

## Нарушение 1: Controller инжектит Repository напрямую (4 файла)

**Правило:** Controller инжектирует ТОЛЬКО Service (не Repository, не Model).

| Controller | Инжектит | Нужно |
|-----------|----------|-------|
| `DashboardBusinessProfileController` | `MerchantRepositoryInterface` | `BusinessProfileService` |
| `DashboardPaymentController` | `PaymentIntentRepositoryInterface` | Добавить `show()` в `DashboardPaymentService` |
| `DashboardRoutingRuleController` | `RoutingRuleRepositoryInterface` | `RoutingRuleService` (новый) |
| `DashboardWebhookEventController` | `WebhookEventRepositoryInterface` | `WebhookEventService` (новый) |

**Исправление:** Создать недостающие сервисы, перенести вызовы из контроллеров.

## Нарушение 2: Service обращается к Model напрямую (11 сервисов)

**Правило:** Сервис вызывает Repository, не Model напрямую.

| Service | Прямой доступ к | Нужен Repository |
|---------|----------------|-----------------|
| `MerchantService` | `Organization::orderByDesc()`, `ApiKey::where()` | Расширить `MerchantRepositoryInterface` |
| `NotificationService` | `AppNotification::where()` (все методы) | `NotificationRepositoryInterface` (новый) |
| `UserSettingsService` | `UserPreference::firstOrCreate()` | `UserPreferenceRepositoryInterface` (новый) |
| `ConnectorHealthService` | `MerchantConnectorAccount::where()`, `DB::table()` | `ConnectorHealthRepositoryInterface` (новый) |
| `AuditLogService` | `Activity::query()` | `AuditLogRepositoryInterface` (новый) |
| `UserRoleService` | `UserRole::with()`, `::updateOrCreate()` | `UserRoleRepositoryInterface` (новый) |
| `DisputeService` | `Dispute::where()`, `DisputeEvidence::create()` | `DisputeRepositoryInterface` (новый) |
| `SavedFilterService` | `SavedFilter::where()` | `SavedFilterRepositoryInterface` (новый) |
| `BusinessProfileService` | `BusinessProfile::where()` | Расширить `MerchantRepositoryInterface` |
| `EventLogService` | `DB::table()` (union queries) | `EventLogRepositoryInterface` (новый) |

**Объём:** ~8 новых Repository interfaces + implementations + регистрация в `RepositoryServiceProvider`.

## Нарушение 3: `response()->json()` вместо Resource (10 контроллеров)

**Правило:** Контроллер возвращает `Resource::make()` / `::collection()`, НЕ `response()->json()`.

| Controller | Методы с `response()->json()` | Нужен Resource |
|-----------|------------------------------|----------------|
| `AnalyticsController` | все 5 через `jsonApiResponse()` | Допустимо — синтетический тип, нет модели |
| `AuditLogController` | `index()` | `AuditLogResource` (новый) |
| `ConnectorHealthController` | `health()`, `errors()` | Допустимо — агрегированные данные |
| `EventLogController` | `index()` | Допустимо — union query, нет модели |
| `DashboardWebhookEventController` | `retry()` | Допустимо — action response |
| `DisputeController` | `submitEvidence()` | `DisputeEvidenceResource` (новый) |
| `NotificationController` | `markAllRead()`, `unreadCount()` | Допустимо — action/counter |
| `SavedFilterController` | `index()`, `store()` | `SavedFilterResource` (новый) |
| `UserRoleController` | `index()`, `store()`, `update()` | `UserRoleResource` (новый) |
| `UserSettingsController` | `show()`, `update()` | `UserPreferenceResource` (новый) |

**Реально нужны:** 4 новых Resource класса (`AuditLogResource`, `DisputeEvidenceResource`, `SavedFilterResource`, `UserRoleResource`, `UserPreferenceResource`).

## Нарушение 4: Enums без HasLabel/HasColor (4 enum)

| Enum | Нет | Влияние |
|------|-----|---------|
| `DisputeStatus` | `HasLabel`, `HasColor` | UI badges в dashboard |
| `DisputeType` | `HasLabel`, `HasColor` | UI badges |
| `PaymentAttemptStatus` | `HasLabel`, `HasColor` | UI badges |
| `UserRole` | `HasLabel` | UI labels |

**Косметическое** — не влияет на работу API.

## Не является нарушением

- `ResolveMerchantContext` middleware обращается к `MerchantAccount::where()` — middleware не Service, прямой доступ допустим
- `response()->json(null, 204)` для delete — стандартный паттерн Laravel
- `response()->json(['count' => $count])` для `unreadCount` — не ресурс, а счётчик

## План исправления

### Фаза 1: Repository layer (3-4 часа)
- [ ] `NotificationRepositoryInterface` + `NotificationRepository`
- [ ] `UserRoleRepositoryInterface` + `UserRoleRepository`
- [ ] `UserPreferenceRepositoryInterface` + `UserPreferenceRepository`
- [ ] `DisputeRepositoryInterface` + `DisputeRepository`
- [ ] `SavedFilterRepositoryInterface` + `SavedFilterRepository`
- [ ] `AuditLogRepositoryInterface` + `AuditLogRepository`
- [ ] `EventLogRepositoryInterface` + `EventLogRepository`
- [ ] `ConnectorHealthRepositoryInterface` + `ConnectorHealthRepository`
- [ ] Расширить `MerchantRepositoryInterface` (listOrgs, listApiKeys, listProfilesByMerchant)
- [ ] Зарегистрировать в `RepositoryServiceProvider`

### Фаза 2: Service layer (2-3 часа)
- [ ] `RoutingRuleService` (новый, оборачивает `RoutingRuleRepository`)
- [ ] `WebhookEventService` (новый, оборачивает `WebhookEventRepository`)
- [ ] Убрать прямой Model-доступ из существующих сервисов
- [ ] Добавить `show()` в `DashboardPaymentService`

### Фаза 3: Resource classes (1-2 часа)
- [ ] `AuditLogResource`
- [ ] `DisputeEvidenceResource`
- [ ] `SavedFilterResource`
- [ ] `UserRoleResource`
- [ ] `UserPreferenceResource`
- [ ] Обновить контроллеры на `Resource::make()` / `::collection()`

### Фаза 4: Controllers (1 час)
- [ ] Убрать Repository injection из 4 контроллеров
- [ ] Заменить на Service injection
- [ ] Убрать `response()->json()` где есть Resource

### Фаза 5: Enums (30 мин)
- [ ] `HasLabel` + `HasColor` на `DisputeStatus`, `DisputeType`, `PaymentAttemptStatus`, `UserRole`

### Фаза 6: Тесты (1 час)
- [ ] Проверить все 125+ dashboard тестов проходят
- [ ] Pint clean
- [ ] Добавить тесты на новые сервисы если нужно
