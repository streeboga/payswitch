import { test as setup, expect } from '@playwright/test'

const AUTH_FILE = 'e2e/.auth/user.json'

setup('authenticate', async ({ page }) => {
  await page.goto('/login')
  await expect(page.getByLabel('Email')).toBeVisible({ timeout: 10000 })
  await page.getByLabel('Email').fill('test@example.com')
  await page.getByLabel('Пароль').fill('password')
  await page.getByRole('button', { name: 'Войти' }).click()
  await expect(page).toHaveURL(/\/overview/, { timeout: 15000 })

  await page.context().storageState({ path: AUTH_FILE })
})
