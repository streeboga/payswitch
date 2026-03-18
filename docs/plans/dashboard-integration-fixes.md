# ТЗ: Интеграция backend ↔ frontend — исправления

## Контекст

Backend API полностью реализован и покрыт тестами (346 тестов).
Frontend SPA написан, но не работает из-за несовпадений форматов и отсутствия данных.

**Корневые причины:**
1. Context switcher (`dashboard-context.ts`) ожидает flat `{ key, name }`, а бэк отдаёт JSON:API
2. Analytics endpoints ожидают merchant context, но мерчант не выбран
3. Profiles endpoint — фронт вызывает несуществующий URL

---

## Часть 1: Backend — изменения

### 1.1. Новый endpoint для profiles по мерчанту

**Проблема:** Фронт вызывает `GET /api/v1/dashboard/merchants/{merchantKey}/profiles`, а бэк имеет только `GET /api/v1/dashboard/profiles` (merchant из header).

**Решение:** Добавить роут-алиас в auth-only группу (без resolve.merchant):

```
GET /api/v1/dashboard/merchants/{merchantKey}/profiles
```

Controller: `DashboardBusinessProfileController::indexByMerchant(string $merchantKey)`
- Найти мерчанта по `$merchantKey`
- Вернуть `BusinessProfile::where('merchant_account_id', $merchant->id)->get()`

### 1.2. Убрать merchant_id из notifications (nullable)

**Текущее:** `AppNotification` имеет `merchant_account_id` nullable — ок, ничего менять не нужно.
`NotificationController` в auth-only группе — ок.

### 1.3. Нет изменений в формате ответов

Все endpoints уже отдают JSON:API. Фронт должен использовать `extractAttributes()` / `extractCollectionAttributes()`.

---

## Часть 2: Frontend — изменения

### 2.1. CRITICAL: `dashboard-context.ts` — использовать JSON:API типы

**Файл:** `dashboard/src/api/endpoints/dashboard-context.ts`

**Было:**
```typescript
interface ContextOrganization { key: string; name: string }
interface ContextListResponse<T> { data: T[] }

listOrganizations() {
  return api.get('dashboard/organizations', ...).json<ContextListResponse<ContextOrganization>>()
}
```

**Стало:**
```typescript
import { JsonApiResource, extractCollectionAttributes } from '../types/json-api'

interface OrgAttributes { name: string; created_at: string }
interface MerchantAttributes { name: string; created_at: string }
interface ProfileAttributes { merchant_id: string; webhook_url: string | null; created_at: string }

export const dashboardContext = {
  async listOrganizations() {
    const res = await api
      .get('dashboard/organizations')
      .json<{ data: JsonApiResource<OrgAttributes>[] }>()
    return res.data.map(r => ({ key: r.id, name: r.attributes.name }))
  },

  async listMerchants(orgKey: string) {
    const res = await api
      .get(`dashboard/organizations/${orgKey}/merchants`)
      .json<{ data: JsonApiResource<MerchantAttributes>[] }>()
    return res.data.map(r => ({ key: r.id, name: r.attributes.name }))
  },

  async listProfiles(merchantKey: string) {
    const res = await api
      .get(`dashboard/merchants/${merchantKey}/profiles`)
      .json<{ data: JsonApiResource<ProfileAttributes>[] }>()
    return res.data.map(r => ({
      key: r.id,
      name: r.attributes.webhook_url ?? r.id,
    }))
  },
}
```

### 2.2. CRITICAL: `use-context-data.ts` — адаптировать возвращаемый тип

**Файл:** `dashboard/src/hooks/use-context-data.ts`

Поскольку `dashboardContext.listOrganizations()` теперь возвращает `Promise<{key, name}[]>`, хуки должны возвращать массив напрямую:

```typescript
export function useOrganizations() {
  return useQuery({
    queryKey: ['context', 'organizations'],
    queryFn: () => dashboardContext.listOrganizations(),
    staleTime: STALE_TIME,
  })
}
```

А в `context-switcher.tsx` вместо `orgsData?.data ?? []` использовать `orgsData ?? []`.

### 2.3. CRITICAL: `context-switcher.tsx` — исправить доступ к данным

**Файл:** `dashboard/src/components/context-switcher/context-switcher.tsx`

```diff
- const organizations = orgsData?.data ?? []
- const merchants = merchantsData?.data ?? []
- const profiles = profilesData?.data ?? []
+ const organizations = orgsData ?? []
+ const merchants = merchantsData ?? []
+ const profiles = profilesData ?? []
```

### 2.4. Все dashboard API endpoints — использовать JSON:API хелперы

**Паттерн для всех endpoint файлов:**

```typescript
// Было (flat):
return api.get('dashboard/payments').json<{ data: Payment[] }>()

// Стало (JSON:API):
import { JsonApiCollectionDocument, extractCollectionAttributes } from '../types/json-api'

interface PaymentAttributes {
  status: string
  amount: number
  currency: string
  // ...
}

async function listPayments(params: Record<string, string>) {
  const res = await api
    .get('dashboard/payments', { searchParams: params })
    .json<JsonApiCollectionDocument<PaymentAttributes>>()
  return {
    items: extractCollectionAttributes(res.data),
    meta: res.meta,
    links: res.links,
  }
}
```

**Файлы для обновления (все в `dashboard/src/api/endpoints/`):**
- `dashboard-payments.ts`
- `dashboard-refunds.ts`
- `dashboard-customers.ts`
- `dashboard-connectors.ts`
- `dashboard-routing-rules.ts`
- `dashboard-api-keys.ts`
- `dashboard-webhooks.ts`
- `dashboard-disputes.ts`
- `dashboard-notifications.ts`
- `dashboard-event-logs.ts`
- `dashboard-audit-log.ts`
- `analytics.ts`

### 2.5. Query параметры — JSON:API формат

**Все фильтры** должны использовать `filter[]` нотацию:

```typescript
// Было:
searchParams: { status: 'succeeded', per_page: '20' }

// Стало:
searchParams: { 'filter[status]': 'succeeded', 'page[size]': '20', 'page[number]': '1' }
```

**Исключение:** Analytics endpoints поддерживают оба формата (`?period=7d` и `?filter[period]=7d`).

### 2.6. Notifications unread-count

**Endpoint:** `GET /api/v1/dashboard/notifications/unread-count`
**Response:** `{ "count": 5 }` (flat, не JSON:API — это счётчик, не ресурс)

**Файл:** `dashboard/src/api/endpoints/dashboard-notifications.ts`
```typescript
async getUnreadCount() {
  const res = await api.get('dashboard/notifications/unread-count').json<{ count: number }>()
  return res.count
}
```

---

## Часть 3: Backend — новый роут для profiles

### Роут

Добавить в auth-only группу (`routes/api.php`):

```php
Route::get('/merchants/{merchantKey}/profiles', [DashboardBusinessProfileController::class, 'indexByMerchant']);
```

### Controller method

```php
public function indexByMerchant(string $merchantKey, Request $request): JsonResponse
{
    $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
    $profiles = BusinessProfile::where('merchant_account_id', $merchant->id)->get();
    return BusinessProfileResource::jsonApiList($profiles, $request);
}
```

---

## Часть 4: Инициализация данных

### Команда `payswitch:seed`

Уже создаёт: Organization → Merchant → BusinessProfile → ApiKey → Stripe connector.

**Дополнительно нужно:**
1. Test connector (для test payments без Stripe):
   ```php
   MerchantConnectorAccount::create([...connector_name => 'test'...])
   ```
2. UserRole (привязка юзера к организации):
   ```php
   UserRole::create(['user_id' => $user->id, 'organization_id' => $org->id, 'role' => 'admin'])
   ```
3. Принимать `--user` опцию для привязки к существующему юзеру

### Полная инициализация

```bash
php artisan migrate
php artisan payswitch:seed   # создаёт demo org + merchant + connectors
# Привязать юзера вручную если seed не делает:
php artisan tinker --execute="App\Models\UserRole::create(['user_id'=>1,'organization_id'=>1,'role'=>'admin'])"
```

---

## Часть 5: Матрица endpoint ↔ auth

| Группа | Middleware | Endpoints |
|--------|-----------|-----------|
| **Auth-only** | `auth:sanctum` | organizations, org/{key}, org/{key}/merchants, merchants/{key}/profiles, notifications/*, settings, saved-filters, audit-log, users/* |
| **Merchant-scoped** | `auth:sanctum` + `resolve.merchant` | analytics/*, payments/*, refunds, customers/*, connectors/*, routing-rules/*, api-keys/*, webhook-events/*, test-payments, event-logs, profiles/*, disputes/* |

**Правило:** Если endpoint фильтрует по `merchant_account_id` — он в merchant-scoped группе. Если по `user_id` или без фильтра — auth-only.

---

## Checklist

### Backend
- [ ] Добавить `GET /merchants/{merchantKey}/profiles` роут + controller method
- [ ] Обновить `payswitch:seed` — test connector + user role + `--user` option
- [ ] Тест на новый profiles endpoint

### Frontend
- [ ] `dashboard-context.ts` — JSON:API парсинг для orgs/merchants/profiles
- [ ] `use-context-data.ts` — адаптировать return types
- [ ] `context-switcher.tsx` — `orgsData ?? []` вместо `orgsData?.data ?? []`
- [ ] Все `dashboard-*.ts` endpoints — JSON:API типы + `extractAttributes`
- [ ] Все `searchParams` — `filter[]` + `page[]` нотация
- [ ] `notifications` endpoint — `unreadCount` flat response
- [ ] Проверить каждую страницу после изменений
