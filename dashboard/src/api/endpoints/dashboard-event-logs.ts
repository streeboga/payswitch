import { getCollection } from '../client'
import type { PaginatedResult } from '../types'
import { parseCollection, buildJsonApiParams } from '../types'

// ─── Entity Type ─────────────────────────────────────────────

export interface EventLogAttributes {
  event_type: string // 'webhook' | 'status_change'
  action: string // e.g. 'payment.succeeded'
  resource_id: string
  status: string
  detail: string | null
  created_at: string
}

// ─── Filter Params ───────────────────────────────────────────

export interface EventLogListParams {
  type?: string
  from?: string
  to?: string
  page?: number
  per_page?: number
}

// ─── API Functions ───────────────────────────────────────────

export const dashboardEventLogs = {
  async list(
    params: EventLogListParams = {},
  ): Promise<PaginatedResult<EventLogAttributes>> {
    const doc = await getCollection<EventLogAttributes>('dashboard/event-logs', {
      searchParams: buildJsonApiParams(params),
    })
    return parseCollection(doc)
  },
} as const
