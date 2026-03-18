import { getCollection } from '../client'
import type { RefundAttributes, PaginatedResult } from '../types'
import { parseCollection, buildJsonApiParams } from '../types'

// ─── Filter Params ──────────────────────────────────────────

export interface RefundListParams {
  status?: string
  payment_id?: string
  from?: string
  to?: string
  search?: string
  sort?: string
  direction?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

// ─── API Functions ──────────────────────────────────────────

export const dashboardRefunds = {
  async list(params: RefundListParams = {}): Promise<PaginatedResult<RefundAttributes>> {
    const doc = await getCollection<RefundAttributes>('dashboard/refunds', {
      searchParams: buildJsonApiParams(params),
    })
    return parseCollection(doc)
  },
} as const
