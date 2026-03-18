import { api, getCollection } from '../client'
import type { PaymentIntentAttributes, PaginatedResult } from '../types'
import { parseCollection, buildJsonApiParams } from '../types'

// ─── Filter Params ──────────────────────────────────────────

export interface PaymentListParams {
  status?: string
  connector?: string
  currency?: string
  amount_min?: string
  amount_max?: string
  from?: string
  to?: string
  capture_method?: string
  customer_id?: string
  search?: string
  sort?: string
  direction?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

// ─── API Functions ──────────────────────────────────────────

export const dashboardPayments = {
  async list(
    params: PaymentListParams = {},
  ): Promise<PaginatedResult<PaymentIntentAttributes>> {
    const doc = await getCollection<PaymentIntentAttributes>('dashboard/payments', {
      searchParams: buildJsonApiParams(params),
    })
    return parseCollection(doc)
  },

  exportCsv(params: PaymentListParams = {}) {
    const sp = buildJsonApiParams(params)
    sp.set('format', 'csv')
    return api.get('dashboard/payments/export', { searchParams: sp }).blob()
  },
} as const
