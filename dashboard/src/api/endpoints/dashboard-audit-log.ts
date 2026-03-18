import { api, getCollection } from '../client'
import type { PaginatedResult } from '../types'
import { parseCollection, buildJsonApiParams } from '../types'

// ─── Entity Type ────────────────────────────────────────────

export interface AuditLogAttributes {
  user_name: string
  user_email: string
  action: string
  resource_type: string
  resource_id: string
  old_values: Record<string, unknown> | null
  new_values: Record<string, unknown> | null
  ip_address: string | null
  created_at: string
}

// ─── Filter Params ──────────────────────────────────────────

export interface AuditLogListParams {
  user?: string
  action?: string
  resource_type?: string
  from?: string
  to?: string
  search?: string
  sort?: string
  direction?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

// ─── API Functions ──────────────────────────────────────────

export const dashboardAuditLog = {
  async list(
    params: AuditLogListParams = {},
  ): Promise<PaginatedResult<AuditLogAttributes>> {
    const doc = await getCollection<AuditLogAttributes>('dashboard/audit-log', {
      searchParams: buildJsonApiParams(params),
    })
    return parseCollection(doc)
  },

  exportCsv(params: AuditLogListParams = {}) {
    return api
      .get('dashboard/audit-log/export', {
        searchParams: buildJsonApiParams(params),
      })
      .blob()
  },
} as const
