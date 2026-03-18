import { api } from '../client'
import type { PaymentIntentAttributes } from '../types'
import { extractAttributes } from '../types'

// ─── Request Types ───────────────────────────────────────────

export interface TestPaymentRequest {
  amount: number
  currency: string
  payment_method: string
  card_number: string
  card_exp_month: string
  card_exp_year: string
  card_cvc: string
  capture_method: string
  connector_name?: string
  description?: string
}

// ─── API Functions ───────────────────────────────────────────

export const dashboardTestPayment = {
  async create(data: TestPaymentRequest) {
    const doc = await api
      .post('dashboard/test-payments', {
        json: data,
      })
      .json<{ data: { type: string; id: string; attributes: PaymentIntentAttributes } }>()
    return extractAttributes(doc.data)
  },
} as const
