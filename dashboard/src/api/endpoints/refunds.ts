import { createResource, getResource } from '../client'
import type { RefundAttributes } from '../types'

export const refunds = {
  get(refundKey: string) {
    return getResource<RefundAttributes>(`refunds/${refundKey}`)
  },

  create(attrs: {
    payment_id: string
    amount?: number
    reason?: string
    metadata?: Record<string, unknown>
  }) {
    return createResource<RefundAttributes>('refunds', attrs)
  },
} as const
