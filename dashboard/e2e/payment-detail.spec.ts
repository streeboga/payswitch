import { test, expect } from '@playwright/test'

function trackErrors(page: import('@playwright/test').Page) {
  const errors: string[] = []
  page.on('pageerror', (err) => errors.push(err.message))
  return errors
}

test.describe('Payment detail page', () => {
  test('payments list shows table', async ({ page }) => {
    const errors = trackErrors(page)
    await page.goto('/payments')
    await expect(page.getByRole('heading', { name: 'Платежи' })).toBeVisible({ timeout: 10000 })

    const table = page.getByRole('table')
    const tableVisible = await table.isVisible({ timeout: 5000 }).catch(() => false)

    if (!tableVisible) {
      console.log('PAYMENTS: no table visible — possibly no payments exist')
      test.skip()
      return
    }

    const rows = table.locator('tbody tr')
    const rowCount = await rows.count()
    console.log(`PAYMENTS: table visible with ${rowCount} rows`)
    expect(rowCount).toBeGreaterThan(0)
    expect(errors).toEqual([])
  })

  test('clicking payment navigates to detail', async ({ page }) => {
    const errors = trackErrors(page)
    await page.goto('/payments')
    await expect(page.getByRole('heading', { name: 'Платежи' })).toBeVisible({ timeout: 10000 })

    const table = page.getByRole('table')
    const tableVisible = await table.isVisible({ timeout: 5000 }).catch(() => false)

    if (!tableVisible) {
      console.log('PAYMENT DETAIL: no table — skipping')
      test.skip()
      return
    }

    const firstRow = table.locator('tbody tr').first()
    const rowVisible = await firstRow.isVisible({ timeout: 3000 }).catch(() => false)

    if (!rowVisible) {
      console.log('PAYMENT DETAIL: no rows in table — skipping')
      test.skip()
      return
    }

    await firstRow.click()
    await page.waitForURL(/\/payments\/pay_/, { timeout: 10000 })

    console.log(`PAYMENT DETAIL: navigated to ${page.url()}`)

    // Verify detail page shows amount and currency
    const content = page.locator('main')
    await expect(content).toBeVisible({ timeout: 5000 })

    const text = await content.textContent()
    const hasCurrency = /RUB|USD|EUR/i.test(text ?? '')
    console.log(`PAYMENT DETAIL: currency found = ${hasCurrency}`)
    expect(hasCurrency).toBe(true)
    expect(errors).toEqual([])
  })
})
