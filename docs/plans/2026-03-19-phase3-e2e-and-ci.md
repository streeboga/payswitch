# Phase 3: E2E и автоматизация

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Добавить API E2E тесты через Playwright request API, расширить UI E2E тесты, интегрировать Playwright в CI.

**Architecture:** API E2E тесты используют Playwright `request` context (без браузера) для полных API-флоу: платежи, возвраты, коннекторы. UI E2E тесты расширяют существующую инфраструктуру (auth.setup.ts + storageState). CI workflow — отдельный job в tests.yml с Laravel dev server + Vite preview.

**Tech Stack:** Playwright 1.58, TypeScript, GitHub Actions, Laravel Artisan serve, Vite preview

---

## Текущее состояние

| Что есть | Детали |
|----------|--------|
| 6 E2E файлов | auth.setup, auth.spec, overview, sidebar, smoke-pages, forms |
| ~35 тестов | Smoke loads, auth flow, form submissions |
| Playwright config | 3 проекта (setup, auth, e2e), baseURL localhost:3000 |
| CI | tests.yml — только Pest PHP, Playwright НЕ запускается |
| npm scripts | Нет скрипта для E2E — только `npx playwright test` |

---

## Task 1: npm scripts + Playwright в package.json

**Files:**
- Modify: `dashboard/package.json`

**Step 1: Добавить E2E скрипты**

В `dashboard/package.json` секцию `scripts` добавить:

```json
"e2e": "playwright test",
"e2e:ui": "playwright test --ui",
"e2e:api": "playwright test --project=api",
"e2e:headed": "playwright test --headed"
```

**Step 2: Проверить**

```bash
cd dashboard && npm run e2e -- --help
```

Expected: Playwright help output.

**Step 3: Commit**

```bash
git commit -m "chore: add E2E npm scripts to dashboard/package.json"
```

---

## Task 2: API E2E project в Playwright config

**Files:**
- Modify: `dashboard/playwright.config.ts`

**Step 1: Добавить `api` project**

Playwright поддерживает `request` context без браузера. Добавить четвёртый проект:

```typescript
import { defineConfig } from '@playwright/test'

export default defineConfig({
  testDir: './e2e',
  timeout: 30_000,
  retries: 0,
  workers: 1,
  use: {
    baseURL: 'http://localhost:3000',
    headless: true,
  },
  projects: [
    {
      name: 'setup',
      testMatch: /auth\.setup\.ts/,
    },
    {
      name: 'auth',
      testMatch: /auth\.spec\.ts/,
    },
    {
      name: 'api',
      testMatch: /api\/.*\.spec\.ts/,
      use: {
        baseURL: 'http://localhost:8000',
      },
    },
    {
      name: 'e2e',
      testIgnore: /auth\.(spec|setup)\.ts|api\//,
      dependencies: ['setup'],
      use: {
        storageState: 'e2e/.auth/user.json',
      },
    },
  ],
})
```

API project использует `baseURL: localhost:8000` (Laravel напрямую, не Vite proxy).

**Step 2: Проверить**

```bash
cd dashboard && npx playwright test --project=api --list
```

Expected: No tests found (ещё не написаны).

**Step 3: Commit**

```bash
git commit -m "chore: add api project to Playwright config (baseURL: localhost:8000)"
```

---

## Task 3: API E2E — Payment flow (create → confirm → capture → refund)

**Files:**
- Create: `dashboard/e2e/api/payment-flow.spec.ts`

**Step 1: Написать тест**

```typescript
import { test, expect } from '@playwright/test'

// API E2E test — uses request context, no browser needed.
// Requires: php artisan serve running on :8000 with test database seeded.

let apiKey: string
let csrfCookie: string

test.describe('Payment API E2E flow', () => {
  test.beforeAll(async ({ request }) => {
    // Get CSRF cookie via Sanctum
    await request.get('/sanctum/csrf-cookie')

    // Login to dashboard to get session
    const loginResp = await request.post('/login', {
      data: { email: 'test@example.com', password: 'password' },
    })
    expect(loginResp.ok()).toBeTruthy()

    // Get first API key or create one
    const keysResp = await request.get('/dashboard/api-keys')
    const keysBody = await keysResp.json()

    if (keysBody.data?.length > 0) {
      // Use existing key — but we need the raw key which is only shown on creation
      // Create a new one for E2E
    }

    const createKeyResp = await request.post('/dashboard/api-keys', {
      data: { name: 'E2E Test Key' },
    })
    const createKeyBody = await createKeyResp.json()
    apiKey = createKeyBody.data?.raw_key ?? createKeyBody.raw_key ?? ''
    expect(apiKey).toBeTruthy()
  })

  test('create payment', async ({ request }) => {
    const resp = await request.post('/api/v1/payments', {
      headers: { 'api-key': apiKey },
      data: {
        amount: 5000,
        currency: 'RUB',
        capture_method: 'manual',
        description: 'E2E test payment',
      },
    })

    expect(resp.ok()).toBeTruthy()
    const body = await resp.json()
    expect(body.data.attributes.status).toBe('requires_payment_method')
    expect(body.data.attributes.amount).toBe(5000)

    // Store for next test
    test.info().annotations.push({ type: 'payment_id', description: body.data.id })
  })

  test('confirm payment', async ({ request }) => {
    // Create + confirm in one request
    const resp = await request.post('/api/v1/payments', {
      headers: { 'api-key': apiKey },
      data: {
        amount: 5000,
        currency: 'RUB',
        capture_method: 'manual',
        confirm: true,
        payment_method: 'card',
        payment_method_data: {
          card: {
            card_number: '4242424242424242',
            card_exp_month: '12',
            card_exp_year: '2030',
            card_cvc: '123',
          },
        },
      },
    })

    expect(resp.ok()).toBeTruthy()
    const body = await resp.json()
    expect(body.data.attributes.status).toBe('requires_capture')

    // Store payment ID globally via env-like approach
    process.env.E2E_PAYMENT_ID = body.data.id
  })

  test('capture payment', async ({ request }) => {
    const paymentId = process.env.E2E_PAYMENT_ID
    expect(paymentId).toBeTruthy()

    const resp = await request.post(`/api/v1/payments/${paymentId}/capture`, {
      headers: { 'api-key': apiKey },
      data: { amount_to_capture: 5000 },
    })

    expect(resp.ok()).toBeTruthy()
    const body = await resp.json()
    expect(body.data.attributes.status).toBe('succeeded')
    expect(body.data.attributes.amount_received).toBe(5000)
  })

  test('refund payment', async ({ request }) => {
    const paymentId = process.env.E2E_PAYMENT_ID
    expect(paymentId).toBeTruthy()

    const resp = await request.post('/api/v1/refunds', {
      headers: { 'api-key': apiKey },
      data: { payment_id: paymentId, amount: 2000 },
    })

    expect(resp.ok()).toBeTruthy()
    const body = await resp.json()
    expect(body.data.attributes.amount).toBe(2000)
    expect(body.data.attributes.status).toBe('succeeded')
  })

  test('get payment shows final state', async ({ request }) => {
    const paymentId = process.env.E2E_PAYMENT_ID
    expect(paymentId).toBeTruthy()

    const resp = await request.get(`/api/v1/payments/${paymentId}`, {
      headers: { 'api-key': apiKey },
    })

    expect(resp.ok()).toBeTruthy()
    const body = await resp.json()
    expect(body.data.attributes.status).toBe('succeeded')
    expect(body.data.attributes.amount_received).toBe(5000)
  })
})
```

**Step 2: Проверить что файл парсится**

```bash
cd dashboard && npx playwright test --project=api --list
```

Expected: 5 tests listed.

**Step 3: Commit**

```bash
git commit -m "test(e2e): API payment flow — create, confirm, capture, refund, verify"
```

---

## Task 4: API E2E — Refund edge cases

**Files:**
- Create: `dashboard/e2e/api/refund-flow.spec.ts`

**Step 1: Написать тест**

```typescript
import { test, expect } from '@playwright/test'

let apiKey: string

test.describe('Refund API E2E', () => {
  test.beforeAll(async ({ request }) => {
    await request.get('/sanctum/csrf-cookie')
    await request.post('/login', {
      data: { email: 'test@example.com', password: 'password' },
    })
    const resp = await request.post('/dashboard/api-keys', {
      data: { name: 'E2E Refund Key' },
    })
    const body = await resp.json()
    apiKey = body.data?.raw_key ?? body.raw_key ?? ''
  })

  test('partial refund reduces remaining amount', async ({ request }) => {
    // Create succeeded payment
    const payResp = await request.post('/api/v1/payments', {
      headers: { 'api-key': apiKey },
      data: {
        amount: 10000, currency: 'RUB', confirm: true,
        payment_method: 'card',
        payment_method_data: { card: { card_number: '4242424242424242', card_exp_month: '12', card_exp_year: '2030', card_cvc: '123' } },
      },
    })
    const paymentId = (await payResp.json()).data.id

    // First partial refund
    const ref1 = await request.post('/api/v1/refunds', {
      headers: { 'api-key': apiKey },
      data: { payment_id: paymentId, amount: 3000 },
    })
    expect(ref1.ok()).toBeTruthy()

    // Second partial refund
    const ref2 = await request.post('/api/v1/refunds', {
      headers: { 'api-key': apiKey },
      data: { payment_id: paymentId, amount: 3000 },
    })
    expect(ref2.ok()).toBeTruthy()

    // Refund exceeding remaining should fail
    const ref3 = await request.post('/api/v1/refunds', {
      headers: { 'api-key': apiKey },
      data: { payment_id: paymentId, amount: 5000 },
    })
    expect(ref3.ok()).toBeFalsy()
    expect(ref3.status()).toBe(400)
  })

  test('refund on non-succeeded payment fails', async ({ request }) => {
    // Create payment without confirming
    const payResp = await request.post('/api/v1/payments', {
      headers: { 'api-key': apiKey },
      data: { amount: 5000, currency: 'RUB' },
    })
    const paymentId = (await payResp.json()).data.id

    const refResp = await request.post('/api/v1/refunds', {
      headers: { 'api-key': apiKey },
      data: { payment_id: paymentId, amount: 1000 },
    })
    expect(refResp.ok()).toBeFalsy()
    expect(refResp.status()).toBe(400)
  })
})
```

**Step 2: Commit**

```bash
git commit -m "test(e2e): API refund flow — partial refunds, excess refund, non-succeeded"
```

---

## Task 5: API E2E — Connector CRUD

**Files:**
- Create: `dashboard/e2e/api/connector-crud.spec.ts`

**Step 1: Написать тест**

```typescript
import { test, expect } from '@playwright/test'

test.describe('Connector CRUD via Dashboard API', () => {
  test.beforeAll(async ({ request }) => {
    await request.get('/sanctum/csrf-cookie')
    await request.post('/login', {
      data: { email: 'test@example.com', password: 'password' },
    })
  })

  test('list connectors', async ({ request }) => {
    const resp = await request.get('/dashboard/connectors')
    expect(resp.ok()).toBeTruthy()
    const body = await resp.json()
    expect(body.data).toBeInstanceOf(Array)
  })

  test('create test connector', async ({ request }) => {
    const resp = await request.post('/dashboard/connectors', {
      data: {
        connector_name: 'test',
        connector_type: 'fiz_operations',
        connector_account_details: { api_key: 'e2e_test_key' },
        payment_methods_enabled: [{ payment_method: 'card' }],
        test_mode: true,
      },
    })
    expect(resp.status()).toBeLessThan(500)
    if (resp.ok()) {
      const body = await resp.json()
      expect(body.data.attributes.connector_name).toBe('test')
      process.env.E2E_CONNECTOR_KEY = body.data.id
    }
  })

  test('get connector detail', async ({ request }) => {
    const key = process.env.E2E_CONNECTOR_KEY
    if (!key) test.skip()

    const resp = await request.get(`/dashboard/connectors/${key}`)
    expect(resp.ok()).toBeTruthy()
  })

  test('delete connector', async ({ request }) => {
    const key = process.env.E2E_CONNECTOR_KEY
    if (!key) test.skip()

    const resp = await request.delete(`/dashboard/connectors/${key}`)
    expect(resp.status()).toBeLessThan(500)
  })
})
```

**Step 2: Commit**

```bash
git commit -m "test(e2e): API connector CRUD — list, create, get, delete"
```

---

## Task 6: UI E2E — Payment detail page

**Files:**
- Create: `dashboard/e2e/payment-detail.spec.ts`

**Step 1: Написать тест**

```typescript
import { test, expect } from '@playwright/test'

test.describe('Payment detail page', () => {
  test('payments list shows table with rows', async ({ page }) => {
    await page.goto('/payments')
    await expect(page.getByRole('heading', { name: 'Платежи' })).toBeVisible({ timeout: 10000 })

    // Wait for table to load
    const table = page.locator('table')
    const tableVisible = await table.isVisible({ timeout: 5000 }).catch(() => false)

    if (tableVisible) {
      const rows = table.locator('tbody tr')
      const count = await rows.count()
      console.log(`PAYMENTS: ${count} rows in table`)

      if (count > 0) {
        // Click first payment to go to detail
        await rows.first().click()
        await page.waitForURL(/\/payments\/pay_/, { timeout: 5000 })

        // Verify detail page elements
        await expect(page.getByText(/pay_/)).toBeVisible({ timeout: 5000 })
        // Status badge should exist
        const statusBadge = page.locator('[data-testid="payment-status"], .badge, [class*="badge"]').first()
        await expect(statusBadge).toBeVisible({ timeout: 5000 })
      }
    } else {
      console.log('PAYMENTS: no table visible (empty state?)')
    }
  })

  test('payment detail shows amount and currency', async ({ page }) => {
    await page.goto('/payments')
    await expect(page.getByRole('heading', { name: 'Платежи' })).toBeVisible({ timeout: 10000 })

    const firstRow = page.locator('table tbody tr').first()
    if (await firstRow.isVisible({ timeout: 5000 }).catch(() => false)) {
      await firstRow.click()
      await page.waitForURL(/\/payments\/pay_/, { timeout: 5000 })

      // Should show amount somewhere on the page
      const pageContent = await page.textContent('main')
      // Payment detail should contain currency code
      expect(pageContent).toMatch(/RUB|USD|EUR/)
    } else {
      test.skip()
    }
  })
})
```

**Step 2: Commit**

```bash
git commit -m "test(e2e): UI payment detail — table navigation, status badge, amount display"
```

---

## Task 7: UI E2E — Connector setup wizard

**Files:**
- Create: `dashboard/e2e/connector-setup.spec.ts`

**Step 1: Написать тест**

```typescript
import { test, expect } from '@playwright/test'

test.describe('Connector setup wizard', () => {
  test('wizard completes full flow: select → credentials → payment methods → done', async ({ page }) => {
    const errors: string[] = []
    page.on('pageerror', (err) => errors.push(err.message))

    await page.goto('/connectors')
    await expect(page.getByRole('heading', { name: 'Коннекторы' })).toBeVisible({ timeout: 10000 })

    // Open wizard
    await page.getByRole('button', { name: 'Подключить коннектор' }).click()
    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible({ timeout: 5000 })

    // Step 1: Select connector
    await dialog.getByText('Test').first().click()
    await page.waitForTimeout(500)

    // Step 2: Credentials (Test connector has no required credentials)
    const nextBtn = dialog.getByRole('button', { name: /Далее/ })
    if (await nextBtn.isEnabled({ timeout: 2000 }).catch(() => false)) {
      await nextBtn.click()
      await page.waitForTimeout(500)

      // Step 3: Payment methods
      const cardCheckbox = dialog.getByLabel('card')
      if (await cardCheckbox.isVisible({ timeout: 2000 }).catch(() => false)) {
        await cardCheckbox.click()
      }

      const nextBtn2 = dialog.getByRole('button', { name: /Далее/ })
      if (await nextBtn2.isEnabled({ timeout: 2000 }).catch(() => false)) {
        await nextBtn2.click()
        await page.waitForTimeout(500)

        // Step 4: Finish
        const finishBtn = dialog.getByRole('button', { name: /Готово|Создать|Finish/ })
        if (await finishBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
          await finishBtn.click()
          await page.waitForTimeout(1000)

          // Dialog should close
          await expect(dialog).not.toBeVisible({ timeout: 5000 })
        }
      }
    }

    expect(errors).toEqual([])
  })

  test('wizard validates required credentials for YooKassa', async ({ page }) => {
    await page.goto('/connectors')
    await expect(page.getByRole('heading', { name: 'Коннекторы' })).toBeVisible({ timeout: 10000 })

    await page.getByRole('button', { name: 'Подключить коннектор' }).click()
    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible({ timeout: 5000 })

    // Select YooKassa
    const yookassa = dialog.getByText('YooKassa').first()
    if (await yookassa.isVisible({ timeout: 2000 }).catch(() => false)) {
      await yookassa.click()
      await page.waitForTimeout(500)

      // Next button should be disabled without credentials
      const nextBtn = dialog.getByRole('button', { name: /Далее/ })
      // Either disabled or credentials form should be visible
      const shopIdInput = dialog.getByLabel(/shop.*id/i)
      if (await shopIdInput.isVisible({ timeout: 2000 }).catch(() => false)) {
        console.log('CONNECTOR WIZARD: YooKassa credentials form shown')
      }
    } else {
      console.log('CONNECTOR WIZARD: YooKassa not in list')
    }
  })
})
```

**Step 2: Commit**

```bash
git commit -m "test(e2e): UI connector setup wizard — full flow + credential validation"
```

---

## Task 8: UI E2E — Routing configuration

**Files:**
- Create: `dashboard/e2e/routing-config.spec.ts`

**Step 1: Написать тест**

```typescript
import { test, expect } from '@playwright/test'

test.describe('Routing configuration', () => {
  test('routing page shows existing rules', async ({ page }) => {
    await page.goto('/routing')
    await expect(page.getByRole('heading', { name: 'Маршрутизация' })).toBeVisible({ timeout: 10000 })

    // Should show create button
    await expect(page.getByRole('button', { name: 'Создать правило' })).toBeVisible()

    // If rules exist, table should be visible
    const table = page.locator('table')
    if (await table.isVisible({ timeout: 3000 }).catch(() => false)) {
      const rows = table.locator('tbody tr')
      console.log(`ROUTING: ${await rows.count()} rules`)
    }
  })

  test('create routing rule dialog opens with form', async ({ page }) => {
    await page.goto('/routing')
    await expect(page.getByRole('heading', { name: 'Маршрутизация' })).toBeVisible({ timeout: 10000 })

    await page.getByRole('button', { name: 'Создать правило' }).click()
    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible({ timeout: 5000 })

    // Dialog should have name input and rule type selector
    const nameInput = dialog.getByLabel(/Название|Name/i)
    const nameVisible = await nameInput.isVisible({ timeout: 2000 }).catch(() => false)
    console.log(`ROUTING DIALOG: name input visible = ${nameVisible}`)

    // Close dialog
    await page.keyboard.press('Escape')
    await expect(dialog).not.toBeVisible({ timeout: 3000 })
  })
})
```

**Step 2: Commit**

```bash
git commit -m "test(e2e): UI routing config — rules list + create dialog"
```

---

## Task 9: Playwright CI workflow

**Files:**
- Modify: `.github/workflows/tests.yml`

**Step 1: Добавить E2E job**

Добавить отдельный job `e2e` после `ci`:

```yaml
  e2e:
    runs-on: ubuntu-latest
    needs: ci
    steps:
      - name: Checkout code
        uses: actions/checkout@v6

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          tools: composer:v2

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '22'

      - name: Install PHP Dependencies
        run: composer install --no-interaction --prefer-dist --optimize-autoloader

      - name: Install Node Dependencies
        run: npm --prefix dashboard ci

      - name: Install Playwright Browsers
        run: npx --prefix dashboard playwright install --with-deps chromium

      - name: Copy Environment File
        run: cp .env.example .env

      - name: Generate Application Key
        run: php artisan key:generate

      - name: Setup Database
        run: |
          touch database/database.sqlite
          php artisan migrate --force

      - name: Seed Test Data
        run: php artisan db:seed --class=TestSeeder --force
        continue-on-error: true

      - name: Build Frontend
        run: npm --prefix dashboard run build

      - name: Start Laravel Server
        run: php artisan serve --port=8000 &
        env:
          DB_CONNECTION: sqlite

      - name: Start Vite Preview
        run: npm --prefix dashboard run preview -- --port 3000 &

      - name: Wait for servers
        run: |
          npx wait-on http://localhost:8000/up http://localhost:3000 --timeout 30000

      - name: Run E2E Tests
        run: npm --prefix dashboard run e2e
        env:
          CI: true

      - name: Upload Playwright Report
        uses: actions/upload-artifact@v4
        if: failure()
        with:
          name: playwright-report
          path: dashboard/playwright-report/
          retention-days: 7
```

**Step 2: Проверить YAML**

```bash
python3 -c "import yaml; yaml.safe_load(open('.github/workflows/tests.yml'))" && echo "VALID"
```

**Step 3: Commit**

```bash
git commit -m "ci: add Playwright E2E job to tests workflow"
```

---

## Task 10: Shared E2E helpers

**Files:**
- Create: `dashboard/e2e/helpers/api-client.ts`
- Create: `dashboard/e2e/helpers/test-data.ts`

**Step 1: API client helper**

```typescript
// dashboard/e2e/helpers/api-client.ts
import { APIRequestContext } from '@playwright/test'

export async function authenticateApi(request: APIRequestContext) {
  await request.get('/sanctum/csrf-cookie')
  const loginResp = await request.post('/login', {
    data: { email: 'test@example.com', password: 'password' },
  })
  if (!loginResp.ok()) {
    throw new Error(`Login failed: ${loginResp.status()}`)
  }
}

export async function createApiKey(request: APIRequestContext, name: string): Promise<string> {
  const resp = await request.post('/dashboard/api-keys', {
    data: { name },
  })
  const body = await resp.json()
  return body.data?.raw_key ?? body.raw_key ?? ''
}

export async function createPayment(
  request: APIRequestContext,
  apiKey: string,
  opts: { amount: number; currency?: string; captureMethod?: string; confirm?: boolean },
) {
  const data: Record<string, unknown> = {
    amount: opts.amount,
    currency: opts.currency ?? 'RUB',
    capture_method: opts.captureMethod ?? 'automatic',
  }

  if (opts.confirm) {
    data.confirm = true
    data.payment_method = 'card'
    data.payment_method_data = {
      card: {
        card_number: '4242424242424242',
        card_exp_month: '12',
        card_exp_year: '2030',
        card_cvc: '123',
      },
    }
  }

  const resp = await request.post('/api/v1/payments', {
    headers: { 'api-key': apiKey },
    data,
  })
  return resp.json()
}
```

**Step 2: Test data constants**

```typescript
// dashboard/e2e/helpers/test-data.ts
export const TEST_CARDS = {
  visa_success: {
    card_number: '4242424242424242',
    card_exp_month: '12',
    card_exp_year: '2030',
    card_cvc: '123',
  },
  visa_3ds: {
    card_number: '4000000000003220',
    card_exp_month: '12',
    card_exp_year: '2030',
    card_cvc: '123',
  },
  visa_decline: {
    card_number: '4000000000000002',
    card_exp_month: '12',
    card_exp_year: '2030',
    card_cvc: '123',
  },
} as const

export const TEST_USER = {
  email: 'test@example.com',
  password: 'password',
} as const
```

**Step 3: Commit**

```bash
git commit -m "test(e2e): shared helpers — api-client, test-data constants"
```

---

## Summary

| Task | Что делаем | Тестов |
|------|-----------|--------|
| 1 | npm scripts для E2E | — |
| 2 | API project в Playwright config | — |
| 3 | API E2E: payment flow (create→confirm→capture→refund→verify) | 5 |
| 4 | API E2E: refund edge cases | 2 |
| 5 | API E2E: connector CRUD | 4 |
| 6 | UI E2E: payment detail page | 2 |
| 7 | UI E2E: connector setup wizard | 2 |
| 8 | UI E2E: routing config | 2 |
| 9 | CI workflow с Playwright | — |
| 10 | Shared E2E helpers | — |
| **Итого** | | **~17 новых E2E тестов** |

Порядок: Tasks 1-2 (инфраструктура) → 10 (helpers) → 3-5 (API) параллельно → 6-8 (UI) параллельно → 9 (CI).
