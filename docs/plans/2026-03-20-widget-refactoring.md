# Widget Refactoring Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix widget architecture — remove Blade, add connector display config, server-driven labels, i18n, embedded widget support.

**Architecture:** Laravel API-only (no Blade), React SPA dashboard, Preact widget package. All payment methods config comes from server via `display_config` on MerchantConnectorAccount.

---

## Context from PSP Research

| PSP | Direct method redirect | JS Widget | API parameter |
|-----|----------------------|-----------|---------------|
| **YooKassa** | YES — `payment_method_data.type: "sbp"` → сразу QR | YES — `confirmation.type: "embedded"` | `payment_method_data.type` |
| **CloudPayments** | NO redirect-flow. Виджет на странице мерчанта | YES — основной способ. Pop-up iframe | `restrictedPaymentMethods` (отключение ненужных) |
| **Robokassa** | YES — `IncCurrLabel=SBP` → сразу SBP | Частично — iframe | `IncCurrLabel` / `PaymentMethods` |

**Вывод:** Виджет должен поддерживать два режима confirm-ответа:
1. `code: "redirect"` → redirect_url → hosted page PSP
2. `code: "widget"` → widget_data → встраиваем JS PSP на страницу

---

## Step 1: Migration — add `display_config` to MerchantConnectorAccount

**Files:**
- Create: migration `add_display_config_to_merchant_connector_accounts`
- Modify: `packages/streeboga/payment-data/src/Models/MerchantConnectorAccount.php` — add to fillable, casts
- Modify: connector DTOs and form requests if needed

**display_config structure:**
```json
{
  "payment_methods": [
    {
      "method": "card",
      "display_name": { "ru": "Банковская карта", "en": "Bank Card" },
      "icon_url": null,
      "enabled": true
    },
    {
      "method": "sbp",
      "display_name": { "ru": "СБП", "en": "SBP" },
      "icon_url": null,
      "enabled": true
    }
  ],
  "show_logo": true,
  "show_name": true,
  "widget_mode": "redirect"
}
```

Column: `json('display_config')->nullable()`. Existing `payment_methods_enabled` stays as-is for backward compat.

Run: `php artisan make:migration add_display_config_to_merchant_connector_accounts`
Run: `php artisan migrate`
Run: `./vendor/bin/pest`

---

## Step 2: Enrich payment methods API response

**Files:**
- Modify: `app/Services/PaymentService.php` — `getAvailablePaymentMethods()` merges display_config
- Modify: `app/Http/Controllers/Api/V1/PublicPaymentController.php` — response includes enriched data
- Test: update `tests/Feature/Api/Payments/PublicPaymentApiTest.php`

**Enriched response format:**
```json
{
  "data": [
    {
      "type": "payment_methods",
      "id": "card",
      "attributes": {
        "payment_method": "card",
        "display_name": "Банковская карта",
        "icon_url": null,
        "mode": "redirect"
      }
    }
  ]
}
```

Logic:
1. Get connectors for profile
2. For each connector, read `display_config.payment_methods` if exists
3. Merge with `payment_methods_enabled` (display_config has priority)
4. Fallback: if no display_config, use `payment_methods_enabled` flat array with no icon/display_name
5. Locale from `?locale=ru` query param determines which `display_name` translation to return

---

## Step 3: Widget renders from server data

**Files:**
- Modify: `widget/src/ui/PaymentWidget.tsx` — remove hardcoded METHOD_LABELS, METHOD_ICONS
- Modify: `widget/src/types.ts` — extend PaymentMethodInfo
- Rebuild widget

**PaymentMethodInfo extended:**
```ts
interface PaymentMethodInfo {
  payment_method: string;
  display_name?: string;  // from server, localized
  icon_url?: string;      // from server
  mode?: 'redirect' | 'widget' | 'inline';
}
```

Widget renders:
- `display_name` if present, else `payment_method`
- `<img src={icon_url}>` if present, else generic payment icon (SVG, not emoji)
- No more hardcoded dictionaries

---

## Step 4: Widget i18n

**Files:**
- Modify: `widget/src/payswitch.ts` — pass locale to API calls and to render
- Modify: `widget/src/ui/PaymentWidget.tsx` — static strings localized
- Modify: `widget/src/api.ts` — add `locale` query param to getPaymentMethods

**Static strings in widget (only ~6):**
- "Pay {amount}" / "Оплатить {amount}"
- "Processing..." / "Обработка..."
- "No payment methods available" / "Нет доступных методов оплаты"
- "Redirecting..." / "Перенаправление..."
- "Payment Succeeded" / "Оплата прошла успешно"
- "Error" / "Ошибка"

Built-in dictionary for ru/en. Custom translations via `WidgetOptions.translations` override.

---

## Step 5: Test PSP → React SPA page

**Files:**
- Delete: `resources/views/test-psp.blade.php`, `resources/lang/test-psp/`
- Modify: `app/Http/Controllers/TestPspController.php` — return JSON, not View
- Modify: `routes/web.php` — remove Blade routes, add JSON API routes
- Create: `dashboard/src/pages/test-psp.tsx` — React page with approve/decline
- Modify: `dashboard/src/app/router.tsx` — add public (no auth) route `/test-psp/$paymentKey`
- Modify: `packages/streeboga/payment-connectors/src/Drivers/TestConnector.php` — URL points to FRONTEND_URL
- Modify: `dashboard/vite.config.ts` — proxy `/test-psp-api/` to backend

**Flow:**
1. TestConnector generates `redirect_url: FRONTEND_URL + /test-psp/{paymentKey}`
2. Browser navigates to dashboard SPA `/test-psp/{key}`
3. React page loads, fetches payment data from `GET /api/v1/test-psp/{key}` (backend)
4. Shows amount + approve/decline buttons (styled like current Blade but in React)
5. On approve → `POST /api/v1/test-psp/{key}/complete` → backend updates status
6. Shows result page with "Return to Store" (return_url) and "View in Dashboard" links
7. i18n via dashboard's i18next (already has ru/en)

**Backend endpoints (JSON API, no auth):**
- `GET /api/v1/test-psp/{paymentKey}` → payment data
- `POST /api/v1/test-psp/{paymentKey}/complete` → {action: "approve"|"decline"} → updated payment

---

## Step 6: Widget `code:"widget"` support (embedded PSP)

**Files:**
- Modify: `widget/src/payswitch.ts` — handlePay checks for widget_data in response
- Modify: `widget/src/ui/PaymentWidget.tsx` — render embedded iframe/script when widget_data present
- Modify: `app/Http/Resources/PublicPaymentIntentResource.php` — expose widget_data in metadata

**Widget flow for embedded mode:**
1. `confirmPayment()` → API returns `status: "requires_customer_action"`, `metadata.widget_data`
2. Widget checks: if `widget_data` present → render embedded PSP
3. `widget_data` contract: `{ script_url: string, params: Record<string, string> }`
4. Widget creates `<script>` tag or iframe with PSP's widget

---

## Execution Order

| Order | Step | Depends On | Risk |
|-------|------|-----------|------|
| 1 | display_config migration | Nothing | Low |
| 2 | Enrich payment methods API | Step 1 | Medium |
| 3 | Widget renders from server | Step 2 | Medium |
| 4 | Widget i18n | Step 2 | Low |
| 5 | Test PSP → React SPA | Nothing | High |
| 6 | Widget embedded mode | Nothing | Medium |

**Steps 3+4 can be parallel. Step 5 is independent but highest risk.**
