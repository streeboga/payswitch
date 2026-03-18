import { createResource, updateResource, deleteResource, getCollection } from '../client'
import type { RoutingRuleAttributes, PaginatedResult } from '../types'
import { parseCollection, buildJsonApiParams, extractAttributes } from '../types'

// ─── Filter Params ──────────────────────────────────────────

export interface RoutingRuleListParams {
  type?: string
  active?: string
  search?: string
  sort?: string
  direction?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

// ─── Create / Update Attrs ──────────────────────────────────

export interface RoutingRuleCreateAttrs {
  type: string
  name: string
  rules: unknown[]
  active?: boolean
  priority?: number
}

export interface RoutingRuleUpdateAttrs {
  name?: string
  rules?: unknown[]
  active?: boolean
  priority?: number
}

// ─── API Functions ──────────────────────────────────────────

export const dashboardRoutingRules = {
  async list(
    params: RoutingRuleListParams = {},
  ): Promise<PaginatedResult<RoutingRuleAttributes>> {
    const doc = await getCollection<RoutingRuleAttributes>('dashboard/routing-rules', {
      searchParams: buildJsonApiParams(params),
    })
    return parseCollection(doc)
  },

  async create(attrs: RoutingRuleCreateAttrs) {
    const doc = await createResource<RoutingRuleAttributes>('dashboard/routing-rules', attrs)
    return extractAttributes(doc.data)
  },

  async update(key: string, attrs: RoutingRuleUpdateAttrs) {
    const doc = await updateResource<RoutingRuleAttributes>(
      `dashboard/routing-rules/${key}`,
      attrs,
    )
    return extractAttributes(doc.data)
  },

  remove(key: string) {
    return deleteResource(`dashboard/routing-rules/${key}`)
  },
} as const
