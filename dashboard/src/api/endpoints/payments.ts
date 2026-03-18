import { createResource, getResource } from '../client'
import { api } from '../client'
import type { PaymentIntentAttributes } from '../types'
import type { JsonApiDocument } from '../types'

export const payments = {
  get(paymentKey: string) {
    return getResource<PaymentIntentAttributes>(`payments/${paymentKey}`)
  },

  create(attrs: {
    amount: number
    currency: string
    merchant_id: string
    profile_id: string
    capture_method?: string
    authentication_type?: string
    customer_id?: string
    description?: string
    return_url?: string
    metadata?: Record<string, unknown>
    confirm?: boolean
    connector?: string
  }) {
    return createResource<PaymentIntentAttributes>('payments', attrs)
  },

  confirm(paymentKey: string) {
    return api
      .post(`payments/${paymentKey}/confirm`)
      .json<JsonApiDocument<PaymentIntentAttributes>>()
  },

  capture(paymentKey: string, attrs?: { amount?: number }) {
    return api
      .post(`payments/${paymentKey}/capture`, {
        json: attrs ?? undefined,
      })
      .json<JsonApiDocument<PaymentIntentAttributes>>()
  },

  cancel(paymentKey: string) {
    return api
      .post(`payments/${paymentKey}/cancel`)
      .json<JsonApiDocument<PaymentIntentAttributes>>()
  },
} as const
