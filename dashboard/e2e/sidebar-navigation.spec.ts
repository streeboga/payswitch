import { test, expect } from '@playwright/test'

test.describe('Sidebar navigation', () => {
  test('sidebar shows all nav groups', async ({ page }) => {
    await page.goto('/overview')
    await expect(page.getByText('ОПЕРАЦИИ')).toBeVisible({ timeout: 10000 })
    await expect(page.getByText('КОНФИГУРАЦИЯ')).toBeVisible()
    await expect(page.getByText('РАЗРАБОТКА')).toBeVisible()
    await expect(page.getByText('УПРАВЛЕНИЕ')).toBeVisible()
  })

  test('clicking nav item navigates', async ({ page }) => {
    await page.goto('/overview')
    await expect(page.getByRole('link', { name: 'Платежи' })).toBeVisible({ timeout: 10000 })
    await page.getByRole('link', { name: 'Платежи' }).click()
    await expect(page).toHaveURL(/\/payments/)
  })
})
