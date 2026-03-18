# ТЗ: Недостающие backend routes

## Проверено по OpenAPI spec (http://payswitch.test/docs/api.json)

25 из 28 mutation routes — есть. Недостаёт 3:

### ❌ Customer CRUD mutations

```
POST   /api/v1/dashboard/customers              — создание клиента
PATCH  /api/v1/dashboard/customers/{customerKey} — обновление клиента
DELETE /api/v1/dashboard/customers/{customerKey} — удаление клиента
```

Сейчас есть только:
- `GET /api/v1/dashboard/customers` — list ✅
- `GET /api/v1/dashboard/customers/{customerKey}` — show ✅

Нужно добавить в `DashboardCustomerController`: `store`, `update`, `destroy`.

Request body (JSON:API):
```json
{
  "data": {
    "type": "customers",
    "attributes": {
      "name": "Test Customer",
      "email": "customer@test.com",
      "phone": "+79001234567",
      "description": "optional"
    }
  }
}
```

### ✅ Всё остальное работает

Проверено E2E тестами:
- `POST /dashboard/api-keys` → 201
- `POST /dashboard/test-payments` → 201
- Все GET endpoints → 200
