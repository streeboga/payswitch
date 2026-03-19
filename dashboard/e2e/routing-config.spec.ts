import { test, expect } from '@playwright/test'

function trackErrors(page: import('@playwright/test').Page) {
  const errors: string[] = []
  page.on('pageerror', (err) => errors.push(err.message))
  return errors
}

test.describe('Routing configuration', () => {
  test('routing page shows create button', async ({ page }) => {
    const errors = trackErrors(page)
    await page.goto('/routing')
    await expect(page.getByRole('heading', { name: 'Маршрутизация' })).toBeVisible({ timeout: 10000 })

    const createBtn = page.getByRole('button', { name: 'Создать правило' })
    await expect(createBtn).toBeVisible({ timeout: 5000 })
    console.log('ROUTING: page loaded with create button')
    expect(errors).toEqual([])
  })

  test('create dialog opens and closes', async ({ page }) => {
    const errors = trackErrors(page)
    await page.goto('/routing')
    await expect(page.getByRole('heading', { name: 'Маршрутизация' })).toBeVisible({ timeout: 10000 })

    await page.getByRole('button', { name: 'Создать правило' }).click()
    await expect(page.getByRole('dialog')).toBeVisible({ timeout: 5000 })
    console.log('ROUTING: create dialog opened')

    await page.keyboard.press('Escape')
    await expect(page.getByRole('dialog')).toBeHidden({ timeout: 5000 })
    console.log('ROUTING: dialog closed via Escape')
    expect(errors).toEqual([])
  })
})
