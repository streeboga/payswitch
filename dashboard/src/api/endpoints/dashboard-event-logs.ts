import { getCollection } from '../client'
import type { PaginatedResult } from '../types'
import { parseCollection, buildJsonApiParams } from '../types'

// ─── Entity Type ─────────────────────────────────────────────

export interface EventLogAttributes {
  event_type: string
  resource_type: string
  resource_id: string
  description: string
  connector: string | null
  metadata: Record<string, unknown> | null
  created_at: string
}

// ─── Filter Params ───────────────────────────────────────────

export interface EventLogListParams {
  type?: string
  resource_type?: string
  from?: string
  to?: string
  search?: string
  sort?: string
  direction?: 'asc' | 'desc'
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
