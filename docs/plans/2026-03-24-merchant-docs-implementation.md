# Merchant Integration Docs — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create merchant-facing integration documentation: 4 MD files + dashboard page "Интеграция"

**Architecture:** MD docs as standalone reference; dashboard page as interactive guide with live keys, code examples, and widget preview. Page follows existing patterns (lazy route, i18n, Card components).

**Tech Stack:** React 19, TanStack Router, i18next, shadcn/ui (Card, Tabs, Badge), existing hooks (useApiKeysList, useMerchantDetail, useProfileDetail, PaymentWidgetPreview)

---

### Task 1: MD docs — docs/merchant/

**Files:**
- Create: `docs/merchant/quickstart.md`
- Create: `docs/merchant/api-reference.md`
- Create: `docs/merchant/widget.md`
- Create: `docs/merchant/webhooks.md`

**Step 1: Create quickstart.md**

```markdown
# Быстрый старт

## 1. Получите API-ключи

В дашборде Payswitch → API-ключи:
- **Publishable Key** (`pk_...`) — для фронтенда (виджет)
- **Secret Key** (`sk_...`) — для бэкенда (создание платежей)

## 2. Создайте платёж (бэкенд)

POST /api/v1/payments
Authorization: Bearer sk_test_xxx
Content-Type: application/vnd.api+json

{
  "amount": 10000,
  "currency": "RUB",
  "description": "Заказ #123",
  "return_url": "https://example.com/success"
}

Ответ (201):

{
  "data": {
    "type": "payments",
    "id": "pay_xxx",
    "attributes": {
      "status": "requires_payment_method",
      "amount": 10000,
      "currency": "RUB",
      "client_secret": "pay_xxx_secret_yyy"
    }
  }
}

Передайте `client_secret` на фронтенд.

## 3. Подключите виджет (фронтенд)

<div id="payment-widget"></div>
<script type="module">
  import { loadPayswitch } from '@payswitch/js';

  const ps = await loadPayswitch('pk_sandbox_xxx', {
    customBackendUrl: 'https://psapi.gnzs.pro'
  });

  const widgets = ps.widgets({
    clientSecret: 'pay_xxx_secret_yyy',
    locale: 'ru'
  });

  widgets.create('payment').mount('#payment-widget');
</script>

Виджет покажет доступные методы оплаты и обработает checkout.

## 4. Обработайте вебхук

Payswitch отправит POST на ваш webhook_url после оплаты:

POST https://your-site.com/webhook
x-webhook-signature-512: <HMAC-SHA512>
Content-Type: application/json

{
  "event_id": "evt_xxx",
  "event_type": "payment_succeeded",
  "content": {
    "payment_id": "pay_xxx",
    "status": "succeeded",
    "amount": 10000,
    "currency": "RUB"
  },
  "updated": "2026-03-24T12:00:00+00:00"
}

Проверьте подпись (см. [Вебхуки](webhooks.md)) и обработайте событие.
```

**Step 2: Create api-reference.md**

Содержание:
- POST /api/v1/payments — создать платёж (все поля из StorePaymentRequest)
- POST /api/v1/payments/{key}/capture — захват
- POST /api/v1/payments/{key}/cancel — отмена
- POST /api/v1/refunds — возврат
- GET /api/v1/refunds/{key} — статус возврата
- Аутентификация: Bearer sk_xxx

**Step 3: Create widget.md**

Содержание:
- loadPayswitch(publishableKey, options)
- widgets({ clientSecret, locale, translations })
- create('payment').mount(selector)
- Events: ready, change, redirect, error
- confirmPayment({ widgets, confirmParams, redirect })
- ConfirmPaymentResult типы
- Кастомизация переводов

**Step 4: Create webhooks.md**

Содержание:
- Формат payload
- Event types: payment_succeeded, payment_failed, payment_cancelled, payment_authorized, payment_captured, refund_succeeded, refund_failed
- Проверка подписи: HMAC-SHA512 от body с ключом payment_response_hash_key
- Ретраи: до 16 попыток, экспоненциальный backoff
- Ответ: HTTP 200 = доставлено

**Step 5: Commit**

```bash
git add docs/merchant/
git commit -m "docs: merchant integration guides — quickstart, API, widget, webhooks"
```

---

### Task 2: i18n translations

**Files:**
- Modify: `dashboard/src/locales/ru.json`
- Modify: `dashboard/src/locales/en.json`

**Step 1: Add Russian translations**

Add to ru.json under new key `"integration"`:

```json
"integration": {
  "title": "Интеграция",
  "subtitle": "Подключите Payswitch к вашему сайту",
  "keysTitle": "Ваши ключи",
  "keysDesc": "Используйте эти ключи для интеграции",
  "publishableKey": "Publishable Key",
  "publishableKeyHint": "Для фронтенда — виджет оплаты",
  "secretKey": "Secret Key",
  "secretKeyHint": "Для бэкенда — создание платежей. Не передавайте на фронтенд!",
  "noKeys": "Создайте API-ключи на странице",
  "goToApiKeys": "API-ключи",
  "webhookTitle": "Webhook",
  "webhookDesc": "Payswitch отправит уведомления на этот URL после оплаты",
  "webhookUrl": "Webhook URL",
  "signingKey": "Ключ подписи",
  "signingKeyHint": "Для проверки HMAC-SHA512 подписи вебхука",
  "noWebhookUrl": "Настройте webhook URL в профиле",
  "goToProfile": "Профиль",
  "createPaymentTitle": "1. Создание платежа",
  "createPaymentDesc": "Отправьте запрос с бэкенда для создания платежа",
  "tryIt": "Попробовать",
  "widgetTitle": "2. Подключение виджета",
  "widgetDesc": "Вставьте виджет на вашу страницу оплаты",
  "widgetPreviewTitle": "Живой пример",
  "webhooksTitle": "3. Обработка вебхуков",
  "webhooksDesc": "Получайте уведомления о статусе платежа",
  "payloadExample": "Пример payload",
  "signatureVerification": "Проверка подписи",
  "eventTypes": "Типы событий",
  "eventPaymentSucceeded": "Успешная оплата",
  "eventPaymentFailed": "Неуспешная оплата",
  "eventPaymentCancelled": "Отмена платежа",
  "eventPaymentAuthorized": "Авторизация (холд)",
  "eventPaymentCaptured": "Захват платежа",
  "eventRefundSucceeded": "Успешный возврат",
  "eventRefundFailed": "Неуспешный возврат",
  "copied": "Скопировано"
}
```

**Step 2: Add English translations**

Same keys with English values in en.json.

**Step 3: Add sidebar key**

Add `"integration": "Интеграция"` to `sidebar` section in ru.json, `"integration": "Integration"` in en.json.

**Step 4: Commit**

```bash
git add dashboard/src/locales/
git commit -m "feat: i18n keys for integration page"
```

---

### Task 3: Dashboard page — integration.tsx

**Files:**
- Create: `dashboard/src/pages/integration.tsx`

**Step 1: Create the page component**

The page contains 4 sections in Cards:
1. **Ваши ключи** — shows publishable + secret keys from useApiKeysList(), with CopyButton
2. **Webhook** — webhook_url + signing key from useProfileDetail(), with CopyButton
3. **Создание платежа** — code block with HTTP request, merchant's secret key substituted. Button "Попробовать" links to /test-payment
4. **Подключение виджета** — JS code block with merchant's publishable key. Live PaymentWidgetPreview below
5. **Обработка вебхуков** — payload JSON example, signature formula, event types table

Key imports:
```typescript
import { useTranslation } from 'react-i18next'
import { useNavigate } from '@tanstack/react-router'
import { Code, Key, Webhook, Play, ExternalLink, Shield, Zap } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs'
import { CopyButton } from '@/components/shared/copy-button'
import { PaymentWidgetPreview } from '@/components/shared/payment-widget-preview'
import { useApiKeysList } from '@/hooks/use-api-keys'
import { useContextStore } from '@/stores/context'
import { useMerchantDetail } from '@/hooks/use-organizations'
import { useProfilesList } from '@/hooks/use-profiles'
```

Code blocks rendered as:
```tsx
<div className="bg-muted rounded-lg p-4 relative">
  <CopyButton value={codeString} className="absolute top-2 right-2" />
  <pre className="text-sm font-mono whitespace-pre-wrap overflow-x-auto">{codeString}</pre>
</div>
```

Export: `export function IntegrationPage()`

**Step 2: Commit**

```bash
git add dashboard/src/pages/integration.tsx
git commit -m "feat: integration page with keys, code examples, widget preview"
```

---

### Task 4: Route + navigation registration

**Files:**
- Modify: `dashboard/src/app/router.tsx`
- Modify: `dashboard/src/components/sidebar/nav-config.ts`

**Step 1: Add lazy import and route in router.tsx**

```typescript
// Add with other lazy imports
const IntegrationPage = lazy(() =>
  import('@/pages/integration').then((m) => ({ default: m.IntegrationPage })),
)

// Add route
const integrationRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/integration',
  component: IntegrationPage,
  beforeLoad: requireAuth,
})

// Add to routeTree.addChildren([...])
```

**Step 2: Add nav item in nav-config.ts**

In the "Разработка" section, add between "Тест-платёж" and "Логи событий":

```typescript
{
  label: t('sidebar.integration'),
  path: '/integration',
  icon: Code,
},
```

**Step 3: Verify page loads**

Run: `cd dashboard && npm run dev`
Navigate to `/integration` — page should render with all sections.

**Step 4: Commit**

```bash
git add dashboard/src/app/router.tsx dashboard/src/components/sidebar/nav-config.ts
git commit -m "feat: register integration page route and sidebar nav"
```

---

### Task 5: Build, test, deploy

**Step 1: TypeScript check**

Run: `cd dashboard && npm run types:check`
Expected: No errors

**Step 2: Lint**

Run: `cd dashboard && npm run lint:check`
Expected: No errors

**Step 3: Build**

Run: `cd dashboard && npm run build`
Expected: Build succeeds

**Step 4: Backend tests**

Run: `./vendor/bin/pest`
Expected: All tests pass (no backend changes in this plan)

**Step 5: Final commit and push**

```bash
git push origin main
```

Deploy triggers automatically via GitHub Actions.

**Step 6: Verify on production**

Navigate to https://payswitch.gnzs.pro/integration — page should render with real keys and widget preview.
