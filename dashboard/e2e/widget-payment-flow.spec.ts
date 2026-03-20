import { test, expect } from '@playwright/test';

test.describe('Payment Widget', () => {
  test('demo page loads and shows missing params error', async ({ page }) => {
    // This test just verifies the demo page works — no backend needed
    await page.goto('http://localhost:4173/demo.html');
    await expect(page.locator('#result')).toContainText('Missing clientSecret or publishableKey');
  });
});
