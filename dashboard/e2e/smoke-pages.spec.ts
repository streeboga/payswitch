import { test, expect } from '@playwright/test'

// Smoke: every page loads without JS runtime errors
const pages = [
  { path: '/payments', text: 'Платежи' },
  { path: '/refunds', text: 'Возвраты' },
  { path: '/customers', text: 'Клиенты' },
  { path: '/connectors', text: 'Коннекторы' },
  { path: '/routing', text: 'Маршрутизация' },
  { path: '/api-keys', text: 'API' },
  { path: '/webhooks', text: 'Вебхуки' },
  { path: '/disputes', text: 'Диспуты' },
  { path: '/event-logs', text: 'Логи' },
  { path: '/test-payment', text: 'платёж' },
  { path: '/settings', text: 'Настройки' },
  { path: '/notifications', text: 'Уведомления' },
]

for (const { path, text } of pages) {
  test(`${path} loads without crash`, async ({ page }) => {
    const errors: string[] = []
    page.on('pageerror', (err) => errors.push(err.message))

    await page.goto(path)
    await page.waitForTimeout(3000)

    // Page text visible (case-insensitive partial match)
    await expect(page.getByText(text).first()).toBeVisible({ timeout: 10000 })

    // Zero JS runtime errors
    expect(errors).toEqual([])
  })
}
