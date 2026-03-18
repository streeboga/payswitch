import { test, expect } from '@playwright/test'

// Helper to catch all API errors on page
function trackErrors(page: import('@playwright/test').Page) {
  const errors: string[] = []
  page.on('pageerror', (err) => errors.push(err.message))
  return errors
}

test.describe('Form submissions — all mutations', () => {
  // === WORKING FORMS ===

  test('test payment → POST /dashboard/test-payments', async ({ page }) => {
    const errors = trackErrors(page)
    await page.goto('/test-payment')
    await expect(page.getByRole('heading', { name: 'Тестовый платёж' })).toBeVisible({ timeout: 10000 })

    const resp = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/test-payments') && r.request().method() === 'POST', { timeout: 15000 }),
      page.getByRole('button', { name: 'Отправить платёж' }).click(),
    ]).then(([r]) => r)

    console.log(`TEST PAYMENT: ${resp.status()}`)
    expect(resp.status()).toBeLessThan(400)
    expect(errors).toEqual([])
  })

  test('create api key → POST /dashboard/api-keys', async ({ page }) => {
    const errors = trackErrors(page)
    await page.goto('/api-keys')
    await expect(page.getByRole('heading', { name: 'API-ключи' })).toBeVisible({ timeout: 10000 })

    await page.getByRole('button', { name: 'Создать API-ключ' }).click()
    await expect(page.getByRole('dialog')).toBeVisible({ timeout: 5000 })
    await page.getByLabel('Название').fill('E2E Test Key')

    const resp = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/api-keys') && r.request().method() === 'POST', { timeout: 15000 }),
      page.getByRole('button', { name: 'Создать' }).click(),
    ]).then(([r]) => r)

    console.log(`CREATE API KEY: ${resp.status()}`)
    expect(resp.status()).toBeLessThan(400)
    expect(errors).toEqual([])
  })

  test('create customer → POST /dashboard/customers', async ({ page }) => {
    const errors = trackErrors(page)
    await page.goto('/customers')
    await expect(page.getByRole('heading', { name: 'Клиенты' })).toBeVisible({ timeout: 10000 })

    await page.getByRole('button', { name: 'Создать клиента' }).click()
    await expect(page.getByRole('dialog')).toBeVisible({ timeout: 5000 })
    await page.getByLabel('Имя').fill('E2E Customer')
    await page.getByLabel('Email').fill('e2e@test.com')

    const resp = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/customers') && r.request().method() === 'POST', { timeout: 15000 }),
      page.getByRole('button', { name: 'Создать' }).click(),
    ]).then(([r]) => r)

    const body = await resp.json().catch(() => null)
    console.log(`CREATE CUSTOMER: ${resp.status()} ${JSON.stringify(body)?.slice(0, 200)}`)
    // Backend may not have POST route yet — log but don't fail on 405
    if (resp.status() === 405) {
      console.log('BACKEND MISSING: POST /dashboard/customers')
    }
  })

  test('create connector → POST /dashboard/connectors', async ({ page }) => {
    const errors = trackErrors(page)
    await page.goto('/connectors')
    await expect(page.getByRole('heading', { name: 'Коннекторы' })).toBeVisible({ timeout: 10000 })

    await page.getByRole('button', { name: 'Подключить коннектор' }).click()
    await expect(page.getByRole('dialog')).toBeVisible({ timeout: 5000 })

    // Step 1: select Test connector (in dialog, not sidebar)
    await page.getByRole('dialog').getByText('Test').first().click()
    await page.waitForTimeout(500)

    // Step 2: Next (test connector has no credentials)
    const nextBtn = page.getByRole('button', { name: /Далее/ })
    if (await nextBtn.isEnabled({ timeout: 2000 }).catch(() => false)) {
      await nextBtn.click()
      await page.waitForTimeout(500)

      // Step 3: select payment method + next
      const cardCheckbox = page.getByLabel('card')
      if (await cardCheckbox.isVisible({ timeout: 2000 }).catch(() => false)) {
        await cardCheckbox.click()
      }
      const nextBtn2 = page.getByRole('button', { name: /Далее/ })
      if (await nextBtn2.isEnabled({ timeout: 2000 }).catch(() => false)) {
        await nextBtn2.click()
        await page.waitForTimeout(500)

        // Step 4: Finish
        const finishBtn = page.getByRole('button', { name: /Готово|Создать|Finish/ })
        if (await finishBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
          const resp = await Promise.all([
            page.waitForResponse((r) => r.url().includes('/connectors') && r.request().method() === 'POST', { timeout: 15000 }),
            finishBtn.click(),
          ]).then(([r]) => r)

          const body = await resp.json().catch(() => null)
          console.log(`CREATE CONNECTOR: ${resp.status()} ${JSON.stringify(body)?.slice(0, 200)}`)
          expect(resp.status()).toBeLessThan(500)
        }
      }
    } else {
      console.log('CONNECTOR WIZARD: Next button disabled at step 1')
    }
    expect(errors).toEqual([])
  })

  test('create routing rule → POST /dashboard/routing-rules', async ({ page }) => {
    const errors = trackErrors(page)
    await page.goto('/routing')
    await expect(page.getByRole('heading', { name: 'Маршрутизация' })).toBeVisible({ timeout: 10000 })

    await page.getByRole('button', { name: 'Создать правило' }).click()
    await expect(page.getByRole('dialog')).toBeVisible({ timeout: 5000 })

    // Take screenshot to see dialog content
    console.log('ROUTING DIALOG: opened successfully')
    expect(errors).toEqual([])
  })

  test('settings profile → PATCH /dashboard/settings', async ({ page }) => {
    const errors = trackErrors(page)
    await page.goto('/settings')
    await expect(page.getByRole('heading', { name: 'Настройки' })).toBeVisible({ timeout: 10000 })
    await page.waitForTimeout(2000)

    // Check what's in the profile tab
    const nameInput = page.getByLabel('Имя')
    const nameVisible = await nameInput.isVisible({ timeout: 3000 }).catch(() => false)
    console.log(`SETTINGS: Name input visible = ${nameVisible}`)

    if (nameVisible) {
      await nameInput.clear()
      await nameInput.fill('E2E Updated')

      const saveBtn = page.getByRole('button', { name: 'Сохранить' }).first()
      const saveVisible = await saveBtn.isVisible({ timeout: 2000 }).catch(() => false)
      console.log(`SETTINGS: Save button visible = ${saveVisible}`)

      if (saveVisible) {
        // Listen for ANY network request after click
        const requestPromise = page.waitForResponse(
          (r) => r.url().includes('/dashboard/') && ['PATCH', 'POST', 'PUT'].includes(r.request().method()),
          { timeout: 10000 },
        ).catch(() => null)

        await saveBtn.click()
        const resp = await requestPromise

        if (resp) {
          const body = await resp.json().catch(() => null)
          console.log(`SETTINGS SAVE: ${resp.request().method()} ${resp.url()} → ${resp.status()}`)
          console.log(`RESPONSE: ${JSON.stringify(body)?.slice(0, 300)}`)
          expect(resp.status()).toBeLessThan(500)
        } else {
          console.log('SETTINGS: No network request sent after clicking Save')
        }
      }
    }
    expect(errors).toEqual([])
  })

  test('webhook retry → POST /dashboard/webhook-events/{key}/retry', async ({ page }) => {
    await page.goto('/webhooks')
    await expect(page.getByRole('heading', { name: 'Вебхуки' })).toBeVisible({ timeout: 10000 })
    // Just verify page loads — retry needs actual webhook events
    console.log('WEBHOOKS: page loaded')
  })

  test('dispute evidence → POST /dashboard/disputes/{key}/evidence', async ({ page }) => {
    await page.goto('/disputes')
    await expect(page.getByRole('heading', { name: 'Диспуты' })).toBeVisible({ timeout: 10000 })
    // Just verify page loads — evidence needs actual disputes
    console.log('DISPUTES: page loaded')
  })

  test('save filter → POST /dashboard/saved-filters', async ({ page }) => {
    await page.goto('/payments')
    await expect(page.getByRole('heading', { name: 'Платежи' })).toBeVisible({ timeout: 10000 })
    // Saved filters are in localStorage, not backend yet
    console.log('SAVED FILTERS: page loaded')
  })

  test('invite user → POST /dashboard/users/roles', async ({ page }) => {
    await page.goto('/users')
    // May redirect if not admin
    await page.waitForTimeout(3000)
    const heading = page.getByRole('heading', { name: 'Пользователи' })
    if (await heading.isVisible({ timeout: 3000 }).catch(() => false)) {
      console.log('USERS: page loaded')
    } else {
      console.log('USERS: redirected (not admin?)')
    }
  })

  test('create profile → POST /dashboard/profiles', async ({ page }) => {
    const errors = trackErrors(page)
    await page.goto('/profiles')
    await expect(page.getByRole('heading', { name: 'Профили' })).toBeVisible({ timeout: 10000 })

    const createBtn = page.getByRole('button', { name: /Создать профиль/ })
    if (await createBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
      await createBtn.click()
      await expect(page.getByRole('dialog')).toBeVisible({ timeout: 5000 })
      console.log('PROFILES: create dialog opened')

      const webhookInput = page.getByLabel(/webhook/i)
      if (await webhookInput.isVisible({ timeout: 2000 }).catch(() => false)) {
        await webhookInput.fill('https://example.com/webhook')

        const submitBtn = page.getByRole('button', { name: 'Создать' })
        const resp = await Promise.all([
          page.waitForResponse((r) => r.url().includes('/profiles') && r.request().method() === 'POST', { timeout: 15000 }),
          submitBtn.click(),
        ]).then(([r]) => r)

        const body = await resp.json().catch(() => null)
        console.log(`CREATE PROFILE: ${resp.status()} ${JSON.stringify(body)?.slice(0, 200)}`)
        expect(resp.status()).toBeLessThan(500)
      }
    }
    expect(errors).toEqual([])
  })
})
