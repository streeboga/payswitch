import { api, getCollection } from '../client'
import type { WebhookEventAttributes, PaginatedResult } from '../types'
import { parseCollection, buildJsonApiParams, extractAttributes } from '../types'

// ─── Filter Params ──────────────────────────────────────────

export interface WebhookListParams {
  type?: string
  delivered?: string
  from?: string
  to?: string
  search?: string
  sort?: string
  direction?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

// ─── API Functions ──────────────────────────────────────────

export const dashboardWebhooks = {
  async list(
    params: WebhookListParams = {},
  ): Promise<PaginatedResult<WebhookEventAttributes>> {
    const doc = await getCollection<WebhookEventAttributes>('dashboard/webhook-events', {
      searchParams: buildJsonApiParams(params),
    })
    return parseCollection(doc)
  },

  async retry(id: string) {
    const doc = await api
      .post(`dashboard/webhook-events/${id}/retry`)
      .json<{ data: { type: string; id: string; attributes: WebhookEventAttributes } }>()
    return extractAttributes(doc.data)
  },
} as const
