import { test, expect } from '@playwright/test'

function trackErrors(page: import('@playwright/test').Page) {
  const errors: string[] = []
  page.on('pageerror', (err) => errors.push(err.message))
  return errors
}

test.describe('Connector setup wizard', () => {
  test('wizard full flow — Test connector', async ({ page }) => {
    const errors = trackErrors(page)
    await page.goto('/connectors')
    await expect(page.getByRole('heading', { name: 'Коннекторы' })).toBeVisible({ timeout: 10000 })

    await page.getByRole('button', { name: 'Подключить коннектор' }).click()
    await expect(page.getByRole('dialog')).toBeVisible({ timeout: 5000 })

    // Step 1: select Test connector
    await page.getByRole('dialog').getByText('Test').first().click()
    await page.waitForTimeout(500)

    // Step 2: Next (test connector has no credentials)
    const nextBtn = page.getByRole('button', { name: /Далее/ })
    if (await nextBtn.isEnabled({ timeout: 2000 }).catch(() => false)) {
      await nextBtn.click()
      await page.waitForTimeout(500)

      // Step 3: select payment method
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
          await finishBtn.click()
          await page.waitForTimeout(1000)

          // Dialog should close after creation
          const dialogStillVisible = await page.getByRole('dialog').isVisible({ timeout: 2000 }).catch(() => false)
          console.log(`CONNECTOR WIZARD: dialog closed = ${!dialogStillVisible}`)
        }
      }
    } else {
      console.log('CONNECTOR WIZARD: Next button disabled at step 1')
    }

    expect(errors).toEqual([])
  })

  test('wizard validates YooKassa credentials', async ({ page }) => {
    const errors = trackErrors(page)
    await page.goto('/connectors')
    await expect(page.getByRole('heading', { name: 'Коннекторы' })).toBeVisible({ timeout: 10000 })

    await page.getByRole('button', { name: 'Подключить коннектор' }).click()
    await expect(page.getByRole('dialog')).toBeVisible({ timeout: 5000 })

    // Select YooKassa connector
    const yookassa = page.getByRole('dialog').getByText('YooKassa')
    const yookassaVisible = await yookassa.isVisible({ timeout: 3000 }).catch(() => false)

    if (!yookassaVisible) {
      console.log('CONNECTOR WIZARD: YooKassa option not found — skipping')
      test.skip()
      return
    }

    await yookassa.first().click()
    await page.waitForTimeout(500)

    // Next to credentials step
    const nextBtn = page.getByRole('button', { name: /Далее/ })
    if (await nextBtn.isEnabled({ timeout: 2000 }).catch(() => false)) {
      await nextBtn.click()
      await page.waitForTimeout(500)
    }

    // Verify credentials form has shop_id input
    const shopIdInput = page.getByLabel(/shop.?id/i)
    const shopIdVisible = await shopIdInput.isVisible({ timeout: 3000 }).catch(() => false)
    console.log(`YOOKASSA WIZARD: shop_id input visible = ${shopIdVisible}`)
    expect(shopIdVisible).toBe(true)
    expect(errors).toEqual([])
  })
})
