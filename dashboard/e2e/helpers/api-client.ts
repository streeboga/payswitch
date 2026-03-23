import type { APIRequestContext } from '@playwright/test'

async function getXsrfToken(request: APIRequestContext): Promise<string> {
  const resp = await request.get('/sanctum/csrf-cookie')
  const cookies = resp
    .headersArray()
    .filter((h) => h.name.toLowerCase() === 'set-cookie')
    .map((h) => h.value)
    .join('; ')
  const match = cookies.match(/XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : ''
}

let merchantKey: string | undefined
let publishableKey: string | undefined
const authenticatedContexts = new WeakSet<APIRequestContext>()

export async function authenticateApi(request: APIRequestContext) {
  if (authenticatedContexts.has(request)) return

  const token = await getXsrfToken(request)
  const loginResp = await request.post('/login', {
    headers: { 'X-XSRF-TOKEN': token },
    data: { email: 'test@example.com', password: 'password' },
  })
  if (!loginResp.ok()) {
    throw new Error(`Login failed: ${loginResp.status()}`)
  }

  authenticatedContexts.add(request)

  // Resolve merchant key and publishable key for API requests
  if (!merchantKey) {
    const merchantsResp = await request.get('/api/v1/dashboard/merchants')
    if (merchantsResp.ok()) {
      const body = await merchantsResp.json()
      merchantKey = body.data?.[0]?.id
      publishableKey = body.data?.[0]?.attributes?.publishable_key
    }
  }
}

export function getMerchantKey(): string | undefined {
  return merchantKey
}

export function getPublishableKey(): string | undefined {
  return publishableKey
}

export function getMerchantHeaders(): Record<string, string> {
  return merchantKey ? { 'X-Merchant-Key': merchantKey } : {}
}

export async function createApiKey(request: APIRequestContext, name: string): Promise<string> {
  const resp = await request.post('/api/v1/dashboard/api-keys', {
    headers: getMerchantHeaders(),
    data: { name },
  })
  const body = await resp.json()
  return body.data?.raw_key ?? body.raw_key ?? ''
}

export async function createPayment(
  request: APIRequestContext,
  apiKey: string,
  opts: {
    amount: number
    currency?: string
    captureMethod?: string
    confirm?: boolean
    card?: Record<string, string>
  },
) {
  const data: Record<string, unknown> = {
    amount: opts.amount,
    currency: opts.currency ?? 'RUB',
    capture_method: opts.captureMethod ?? 'automatic',
  }

  if (opts.confirm) {
    data.confirm = true
    data.payment_method = 'card'
    data.payment_method_data = {
      card: opts.card ?? {
        card_number: '4242424242424242',
        card_exp_month: '12',
        card_exp_year: '2030',
        card_cvc: '123',
      },
    }
  }

  const resp = await request.post('/api/v1/payments', {
    headers: { 'api-key': apiKey },
    data,
  })
  return { resp, body: await resp.json() }
}
