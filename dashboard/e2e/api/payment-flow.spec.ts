import { test, expect, type APIRequestContext } from '@playwright/test'
import { authenticateApi, createApiKey } from '../helpers/api-client'
import { TEST_CARDS } from '../helpers/test-data'

test.describe.serial('Payment flow — create, confirm, capture, refund, verify', () => {
  let request: APIRequestContext
  let apiKey: string
  let paymentId: string

  test.beforeAll(async ({ playwright }) => {
    request = await playwright.request.newContext({
      baseURL: 'http://localhost:8000',
    })
    await authenticateApi(request)
    apiKey = await createApiKey(request, 'e2e-payment-flow')
  })

  test.afterAll(async () => {
    await request.dispose()
  })

  const apiHeaders = () => ({ 'api-key': apiKey })

  test('create payment', async () => {
    const resp = await request.post('/api/v1/payments', {
      headers: apiHeaders(),
      data: {
        amount: 5000,
        currency: 'RUB',
        capture_method: 'manual',
      },
    })

    expect(resp.status()).toBe(201)

    const body = await resp.json()
    paymentId = body.data.id
    expect(paymentId).toBeTruthy()
    expect(body.data.attributes.status).toBe('requires_payment_method')
  })

  test('confirm payment', async () => {
    const resp = await request.post(`/api/v1/payments/${paymentId}/confirm`, {
      headers: apiHeaders(),
      data: {
        payment_method: 'card',
        payment_method_data: {
          card: TEST_CARDS.visa_success,
        },
      },
    })

    expect(resp.ok()).toBeTruthy()

    const body = await resp.json()
    expect(body.data.attributes.status).toBe('requires_capture')
  })

  test('capture payment', async () => {
    const resp = await request.post(`/api/v1/payments/${paymentId}/capture`, {
      headers: apiHeaders(),
      data: {
        amount_to_capture: 5000,
      },
    })

    expect(resp.ok()).toBeTruthy()

    const body = await resp.json()
    expect(body.data.attributes.status).toBe('succeeded')
    expect(body.data.attributes.amount_received).toBe(5000)
  })

  test('partial refund', async () => {
    const resp = await request.post('/api/v1/refunds', {
      headers: apiHeaders(),
      data: {
        payment_id: paymentId,
        amount: 2000,
      },
    })

    expect(resp.ok()).toBeTruthy()

    const body = await resp.json()
    expect(body.data.attributes.status).toBe('succeeded')
  })

  test('get payment final state', async () => {
    const resp = await request.get(`/api/v1/payments/${paymentId}`, {
      headers: apiHeaders(),
    })

    expect(resp.ok()).toBeTruthy()

    const body = await resp.json()
    expect(body.data.attributes.status).toBe('succeeded')
    expect(body.data.attributes.amount_received).toBe(5000)
  })
})
