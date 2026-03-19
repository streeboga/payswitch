import { test, expect } from '@playwright/test'
import { authenticateApi, createApiKey, createPayment } from '../helpers/api-client'
import { TEST_CARDS } from '../helpers/test-data'

test.describe('Refund edge cases', () => {
  let apiKey: string

  test.beforeAll(async ({ request }) => {
    await authenticateApi(request)
    apiKey = await createApiKey(request, `refund-e2e-${Date.now()}`)
  })

  test('partial refund reduces remaining and over-refund fails', async ({ request }) => {
    // Create a succeeded payment of 5000
    const { body: payment } = await createPayment(request, apiKey, {
      amount: 5000,
      confirm: true,
      card: TEST_CARDS.visa_success,
    })
    const paymentId = payment.data.id

    // First partial refund: 2000
    const refund1 = await request.post('/api/v1/refunds', {
      headers: { 'api-key': apiKey },
      data: { payment_id: paymentId, amount: 2000 },
    })
    expect(refund1.status()).toBeLessThan(500)
    expect(refund1.ok()).toBeTruthy()

    // Second partial refund: 2000
    const refund2 = await request.post('/api/v1/refunds', {
      headers: { 'api-key': apiKey },
      data: { payment_id: paymentId, amount: 2000 },
    })
    expect(refund2.status()).toBeLessThan(500)
    expect(refund2.ok()).toBeTruthy()

    // Third refund of 2000 should fail — only 1000 remaining
    const refund3 = await request.post('/api/v1/refunds', {
      headers: { 'api-key': apiKey },
      data: { payment_id: paymentId, amount: 2000 },
    })
    expect(refund3.status()).toBe(400)
  })

  test('refund on non-succeeded payment fails', async ({ request }) => {
    // Create a payment without confirming it
    const { body: payment } = await createPayment(request, apiKey, {
      amount: 3000,
      confirm: false,
    })
    const paymentId = payment.data.id

    // Attempt refund on unconfirmed payment
    const refund = await request.post('/api/v1/refunds', {
      headers: { 'api-key': apiKey },
      data: { payment_id: paymentId, amount: 1000 },
    })
    expect(refund.status()).toBe(400)
  })
})
