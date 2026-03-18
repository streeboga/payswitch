# ТЗ: Исправления фронтенда для dashboard

## Проблема

При загрузке overview (http://localhost:3000/overview) — "Something went wrong":
- `notifications/unread-count` → 500 (таблица не мигрирована)
- `analytics/*` → 404 (X-Merchant-Key не отправляется — мерчант не выбран)

## Задачи

### 1. Миграция новых таблиц

```bash
php artisan migrate
```

Новые таблицы: `notifications`, `disputes`, `dispute_evidences`, `user_roles`, `user_preferences`, `saved_filters`.

### 2. Автовыбор мерчанта при загрузке

**Проблема:** Дропдауны "ОРГАНИЗАЦИЯ" и "МЕРЧАНТ" пустые. Без выбранного мерчанта `X-Merchant-Key` не отправляется → все merchant-scoped endpoints возвращают 404.

**Решение в `context-switcher.tsx` / `context.ts`:**
1. При загрузке вызвать `GET /api/v1/dashboard/organizations`
2. Если есть организации — автовыбрать первую
3. Загрузить мерчантов: `GET /api/v1/dashboard/organizations/{orgKey}/merchants`
4. Автовыбрать первого мерчанта → установить `merchantKey` в store
5. Все последующие запросы к merchant-scoped endpoints получат `X-Merchant-Key` header

**Где хранится:** `src/stores/context.ts` (Zustand store)
**Где отправляется:** `src/api/client.ts` — ky instance, interceptor добавляет `X-Merchant-Key` header

### 3. Graceful handling — нет мерчанта

Если у пользователя нет ни одной организации/мерчанта:
- Analytics/payments/refunds — показать empty state "Создайте организацию и мерчанта"
- Не делать запросы к merchant-scoped endpoints

### 4. Эндпоинты — две группы

**Без X-Merchant-Key (auth:sanctum only):**
- `GET /api/v1/dashboard/organizations`
- `GET /api/v1/dashboard/organizations/{orgKey}`
- `GET /api/v1/dashboard/organizations/{orgKey}/merchants`
- `GET /api/v1/dashboard/notifications/unread-count`
- `GET /api/v1/dashboard/notifications`
- `PATCH /api/v1/dashboard/notifications/{key}/read`
- `POST /api/v1/dashboard/notifications/mark-all-read`
- `DELETE /api/v1/dashboard/notifications/{key}`
- `GET /api/v1/dashboard/settings`
- `PATCH /api/v1/dashboard/settings`
- `GET /api/v1/dashboard/saved-filters`
- `POST /api/v1/dashboard/saved-filters`
- `DELETE /api/v1/dashboard/saved-filters/{id}`
- `GET /api/v1/dashboard/audit-log`
- `GET /api/v1/dashboard/audit-log/export`
- `GET /api/v1/dashboard/users`
- `POST /api/v1/dashboard/users/roles`
- `PATCH /api/v1/dashboard/users/roles/{id}`
- `DELETE /api/v1/dashboard/users/roles/{id}`

**С X-Merchant-Key (требуется выбранный мерчант):**
- `GET /api/v1/dashboard/analytics/*` (5 endpoints)
- `GET /api/v1/dashboard/payments` / `payments/export` / `payments/{key}`
- `GET /api/v1/dashboard/refunds`
- `GET /api/v1/dashboard/customers` / `customers/{key}`
- `GET/POST/PATCH/DELETE /api/v1/dashboard/connectors[/{key}]`
- `GET /api/v1/dashboard/connectors/{key}/health` / `health/errors`
- `GET/POST/PATCH/DELETE /api/v1/dashboard/routing-rules[/{key}]`
- `GET/POST/DELETE /api/v1/dashboard/api-keys[/{id}]`
- `GET /api/v1/dashboard/webhook-events` / `webhook-events/{key}/retry`
- `POST /api/v1/dashboard/test-payments`
- `GET /api/v1/dashboard/event-logs`
- `GET/POST/PATCH /api/v1/dashboard/profiles[/{key}]`
- `GET /api/v1/dashboard/disputes[/{key}]`
- `POST /api/v1/dashboard/disputes/{key}/evidence`

### 5. Query параметры — JSON:API формат

Бэк поддерживает оба формата (flat + nested) для analytics:
- ✅ `?period=7d` (flat — текущий фронт)
- ✅ `?filter[period]=7d` (JSON:API — предпочтительно)

Для остальных endpoints — JSON:API формат:
- `?filter[status]=succeeded`
- `?page[size]=20&page[number]=1`
- `?sort=-created_at`
