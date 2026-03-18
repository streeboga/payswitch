import { test, expect } from '@playwright/test'

test.describe('Overview page', () => {
  test('renders without JS errors', async ({ page }) => {
    const errors: string[] = []
    page.on('pageerror', (err) => errors.push(err.message))

    await page.goto('/overview')
    await expect(page.getByRole('heading', { name: 'Обзор' })).toBeVisible({ timeout: 10000 })
    await page.waitForTimeout(3000)

    expect(errors).toEqual([])
  })

  test('shows heading and period filter', async ({ page }) => {
    await page.goto('/overview')
    await expect(page.getByRole('heading', { name: 'Обзор' })).toBeVisible({ timeout: 10000 })
    await expect(page.getByRole('combobox', { name: 'Период' })).toBeVisible()
  })

  test('context switcher auto-selects org and merchant', async ({ page }) => {
    await page.goto('/overview')
    await page.waitForTimeout(3000)

    const orgTrigger = page.locator('[data-testid="org-select"]')
    await expect(orgTrigger).toBeVisible()
    await expect(orgTrigger).not.toContainText('Выберите')
  })

  test('metric cards render without crash', async ({ page }) => {
    await page.goto('/overview')
    await page.waitForTimeout(5000)

    await expect(page.getByText('Оборот')).toBeVisible()
    await expect(page.getByText('Транзакции')).toBeVisible()
    await expect(page.getByText('Конверсия')).toBeVisible()
  })
})
