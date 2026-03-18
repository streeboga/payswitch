import { api, getCollection } from '../client'
import type { PaginatedResult } from '../types'
import { parseCollection, buildJsonApiParams } from '../types'

// ─── Entity Type ────────────────────────────────────────────

export interface NotificationAttributes {
  type: string
  title: string
  body: string
  resource_type: string | null
  resource_id: string | null
  read_at: string | null
  created_at: string
}

// ─── Filter Params ──────────────────────────────────────────

export interface NotificationListParams {
  type?: string
  read?: string
  from?: string
  to?: string
  sort?: string
  direction?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

// ─── API Functions ──────────────────────────────────────────

export const dashboardNotifications = {
  async list(
    params: NotificationListParams = {},
  ): Promise<PaginatedResult<NotificationAttributes>> {
    const doc = await getCollection<NotificationAttributes>('dashboard/notifications', {
      searchParams: buildJsonApiParams(params),
    })
    return parseCollection(doc)
  },

  markRead(ids: string[]) {
    return api.post('dashboard/notifications/mark-read', {
      json: { ids },
    })
  },

  markAllRead() {
    return api.post('dashboard/notifications/mark-all-read')
  },

  deleteRead() {
    return api.delete('dashboard/notifications/read')
  },

  unreadCount() {
    return api.get('dashboard/notifications/unread-count').json<{ count: number }>()
  },
} as const
