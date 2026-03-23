import { test, expect, type APIRequestContext } from '@playwright/test'

const BASE_URL = process.env.E2E_API_URL ?? 'http://localhost:8000'
const SECRET_KEY = process.env.E2E_SECRET_KEY ?? 'snd_01KM5VQYHYBAEB5SJ7VXC731EV'
const PUBLISHABLE_KEY = process.env.E2E_PUBLISHABLE_KEY ?? 'pk_snd_01KM1NJATEJ91E5GKTBZ00NMJM'

/**
 * E2E: Full widget payment flow via Public API
 *
 * 1. Create payment intent (secret key)
 * 2. Get payment via public API (publishable key + client_secret)
 * 3. List payment methods (publishable key + client_secret)
 * 4. Confirm payment → redirect to test-psp
 * 5. Approve on test-psp → payment succeeded
 * 6. Verify final state
 */
test.describe.serial('Widget payment flow — public API + test PSP', () => {
  let request: APIRequestContext
  let paymentId: string
  let clientSecret: string

  test.beforeAll(async ({ playwright }) => {
    request = await playwright.request.newContext({ baseURL: BASE_URL })
  })

  test.afterAll(async () => {
    await request.dispose()
  })

  test('create payment intent', async () => {
    const resp = await request.post('/api/v1/payments', {
      headers: { 'api-key': SECRET_KEY, Accept: 'application/vnd.api+json' },
      data: {
        amount: 15000,
        currency: 'RUB',
        return_url: 'https://merchant.example.com/result',
      },
    })

    expect(resp.status()).toBe(201)
    const body = await resp.json()
    paymentId = body.data.id
    clientSecret = body.data.attributes.client_secret
    expect(paymentId).toBeTruthy()
    expect(clientSecret).toContain('_secret_')
    expect(body.data.attributes.status).toBe('requires_payment_method')
  })

  test('public show returns limited fields', async () => {
    const resp = await request.get(
      `/api/v1/payments/${paymentId}?client_secret=${encodeURIComponent(clientSecret)}`,
      { headers: { 'api-key': PUBLISHABLE_KEY, Accept: 'application/vnd.api+json' } },
    )

    expect(resp.ok()).toBeTruthy()
    const body = await resp.json()
    const attrs = body.data.attributes
    expect(attrs.status).toBe('requires_payment_method')
    expect(attrs.amount).toBe(15000)
    expect(attrs.currency).toBe('RUB')
    expect(attrs.client_secret).toBeUndefined()
  })

  test('public show rejects without client_secret', async () => {
    const resp = await request.get(`/api/v1/payments/${paymentId}`, {
      headers: { 'api-key': PUBLISHABLE_KEY, Accept: 'application/vnd.api+json' },
    })
    expect(resp.status()).toBe(403)
  })

  test('payment methods returns available methods with locale', async () => {
    const resp = await request.get(
      `/api/v1/payments/${paymentId}/payment-methods?client_secret=${encodeURIComponent(clientSecret)}&locale=ru`,
      { headers: { 'api-key': PUBLISHABLE_KEY, Accept: 'application/vnd.api+json' } },
    )

    expect(resp.ok()).toBeTruthy()
    const body = await resp.json()
    expect(body.data).toBeInstanceOf(Array)
    expect(body.data.length).toBeGreaterThan(0)

    const first = body.data[0]
    expect(first.type).toBe('payment_methods')
    expect(first.attributes.payment_method).toBeTruthy()
    expect(first.attributes.mode).toBeTruthy()
  })

  test('confirm payment triggers redirect flow', async () => {
    const resp = await request.post(`/api/v1/payments/${paymentId}/confirm`, {
      headers: {
        'api-key': PUBLISHABLE_KEY,
        'Content-Type': 'application/vnd.api+json',
        Accept: 'application/vnd.api+json',
      },
      data: {
        client_secret: clientSecret,
        payment_method: 'card',
      },
    })

    expect(resp.ok()).toBeTruthy()
    const body = await resp.json()
    expect(body.data.attributes.status).toBe('requires_customer_action')
    expect(body.data.attributes.metadata.redirect_url).toContain('/test-psp/')
  })

  test('test PSP show returns payment data', async () => {
    const resp = await request.get(`/api/v1/test-psp/${paymentId}`)

    expect(resp.ok()).toBeTruthy()
    const body = await resp.json()
    expect(body.data.type).toBe('test_psp_payment')
    expect(body.data.attributes.amount).toBe(15000)
    expect(body.data.attributes.currency).toBe('RUB')
    expect(body.data.attributes.status).toBe('requires_customer_action')
  })

  test('test PSP approve completes payment', async () => {
    const resp = await request.post(`/api/v1/test-psp/${paymentId}/complete`, {
      headers: { 'Content-Type': 'application/json' },
      data: { action: 'approve' },
    })

    expect(resp.ok()).toBeTruthy()
    const body = await resp.json()
    expect(body.data.attributes.status).toBe('succeeded')
    expect(body.data.attributes.success).toBe(true)
    expect(body.data.attributes.return_url).toContain('payment_id=')
    expect(body.data.attributes.dashboard_url).toContain(`/payments/${paymentId}`)
  })

  test('payment final state is succeeded', async () => {
    const resp = await request.get(`/api/v1/payments/${paymentId}`, {
      headers: { 'api-key': SECRET_KEY, Accept: 'application/vnd.api+json' },
    })

    expect(resp.ok()).toBeTruthy()
    const body = await resp.json()
    expect(body.data.attributes.status).toBe('succeeded')
    expect(body.data.attributes.amount_received).toBe(15000)
  })
})

test.describe.serial('Widget payment flow — decline path', () => {
  let request: APIRequestContext
  let paymentId: string
  let clientSecret: string

  test.beforeAll(async ({ playwright }) => {
    request = await playwright.request.newContext({ baseURL: BASE_URL })
  })

  test.afterAll(async () => {
    await request.dispose()
  })

  test('create and confirm payment', async () => {
    const createResp = await request.post('/api/v1/payments', {
      headers: { 'api-key': SECRET_KEY, Accept: 'application/vnd.api+json' },
      data: { amount: 5000, currency: 'RUB' },
    })
    const createBody = await createResp.json()
    paymentId = createBody.data.id
    clientSecret = createBody.data.attributes.client_secret

    const confirmResp = await request.post(`/api/v1/payments/${paymentId}/confirm`, {
      headers: {
        'api-key': PUBLISHABLE_KEY,
        'Content-Type': 'application/vnd.api+json',
        Accept: 'application/vnd.api+json',
      },
      data: { client_secret: clientSecret, payment_method: 'card' },
    })
    const confirmBody = await confirmResp.json()
    expect(confirmBody.data.attributes.status).toBe('requires_customer_action')
  })

  test('test PSP decline fails payment', async () => {
    const resp = await request.post(`/api/v1/test-psp/${paymentId}/complete`, {
      headers: { 'Content-Type': 'application/json' },
      data: { action: 'decline' },
    })

    expect(resp.ok()).toBeTruthy()
    const body = await resp.json()
    expect(body.data.attributes.status).toBe('failed')
    expect(body.data.attributes.success).toBe(false)
  })

  test('payment final state is failed', async () => {
    const resp = await request.get(`/api/v1/payments/${paymentId}`, {
      headers: { 'api-key': SECRET_KEY, Accept: 'application/vnd.api+json' },
    })
    const body = await resp.json()
    expect(body.data.attributes.status).toBe('failed')
  })
})
