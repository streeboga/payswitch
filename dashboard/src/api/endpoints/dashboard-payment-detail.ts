import { api } from '../client'
import type { JsonApiResource, PaymentIntentAttributes, RefundAttributes } from '../types'

// ─── Response Types ──────────────────────────────────────────

export interface PaymentAttemptAttributes {
  connector: string
  status: string
  amount: number
  currency: string
  transaction_id: string | null
  error_code: string | null
  error_message: string | null
  created_at: string
}

export interface PaymentDetailResponse {
  data: JsonApiResource<PaymentIntentAttributes>
  included?: JsonApiResource[]
}

export interface PaymentDetail {
  payment: PaymentIntentAttributes & { id: string }
  attempts: (PaymentAttemptAttributes & { id: string })[]
  refunds: (RefundAttributes & { id: string })[]
}

// ─── Parser ──────────────────────────────────────────────────

function parseDetailResponse(response: PaymentDetailResponse): PaymentDetail {
  const payment = {
    ...response.data.attributes,
    id: response.data.id,
  }

  const attempts: (PaymentAttemptAttributes & { id: string })[] = []
  const refunds: (RefundAttributes & { id: string })[] = []

  for (const resource of response.included ?? []) {
    if (resource.type === 'payment_attempts') {
      attempts.push({
        ...(resource.attributes as unknown as PaymentAttemptAttributes),
        id: resource.id,
      })
    } else if (resource.type === 'refunds') {
      refunds.push({
        ...(resource.attributes as unknown as RefundAttributes),
        id: resource.id,
      })
    }
  }

  return { payment, attempts, refunds }
}

// ─── API Functions ───────────────────────────────────────────

export const dashboardPaymentDetail = {
  get(paymentKey: string): Promise<PaymentDetail> {
    return api
      .get(`dashboard/payments/${paymentKey}`, {
        searchParams: { include: 'attempts,refunds' },
      })
      .json<PaymentDetailResponse>()
      .then(parseDetailResponse)
  },
} as const
