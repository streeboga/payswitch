import { test, expect } from '@playwright/test'

test.describe('Auth flow', () => {
  test('login page renders form', async ({ page }) => {
    await page.goto('/login')
    await expect(page.getByText('Payswitch')).toBeVisible()
    await expect(page.getByLabel('Email')).toBeVisible()
    await expect(page.getByLabel('Пароль')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Войти' })).toBeVisible()
  })

  test('invalid credentials show error', async ({ page }) => {
    await page.goto('/login')
    await page.getByLabel('Email').fill('wrong@test.com')
    await page.getByLabel('Пароль').fill('wrongpassword')
    await page.getByRole('button', { name: 'Войти' }).click()

    await expect(page.getByRole('alert')).toBeVisible({ timeout: 5000 })
  })

  test('valid login redirects to overview', async ({ page }) => {
    await page.goto('/login')
    await page.getByLabel('Email').fill('test@example.com')
    await page.getByLabel('Пароль').fill('password')
    await page.getByRole('button', { name: 'Войти' }).click()

    await expect(page).toHaveURL(/\/overview/, { timeout: 15000 })
  })

  test('unauthenticated user redirected to login', async ({ page }) => {
    await page.goto('/overview')
    await expect(page).toHaveURL(/\/login/, { timeout: 10000 })
  })

  test('logout works', async ({ page }) => {
    // Login first
    await page.goto('/login')
    await page.getByLabel('Email').fill('test@example.com')
    await page.getByLabel('Пароль').fill('password')
    await page.getByRole('button', { name: 'Войти' }).click()
    await expect(page).toHaveURL(/\/overview/, { timeout: 15000 })

    // Logout
    await page.getByRole('button', { name: 'Выйти' }).click()
    await expect(page).toHaveURL(/\/login/, { timeout: 5000 })
  })
})
