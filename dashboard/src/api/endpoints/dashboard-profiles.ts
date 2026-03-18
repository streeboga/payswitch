import { getCollection, getResource, createResource, updateResource, deleteResource } from '../client'
import type { BusinessProfileAttributes, PaginatedResult } from '../types'
import { parseCollection, buildJsonApiParams, extractAttributes } from '../types'

// ─── Filter Params ──────────────────────────────────────────

export interface ProfileListParams {
  search?: string
  sort?: string
  direction?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

// ─── Extended Attributes ────────────────────────────────────

export type ProfileListItem = BusinessProfileAttributes & {
  connectors_count: number
  routing_rules_count: number
}

// ─── API Functions ──────────────────────────────────────────

export const dashboardProfiles = {
  async list(params: ProfileListParams = {}): Promise<PaginatedResult<ProfileListItem>> {
    const doc = await getCollection<ProfileListItem>('dashboard/profiles', {
      searchParams: buildJsonApiParams(params),
    })
    return parseCollection(doc)
  },

  async get(profileKey: string) {
    const doc = await getResource<ProfileListItem>(`dashboard/profiles/${profileKey}`)
    return extractAttributes(doc.data)
  },

  async create(data: { webhook_url?: string }) {
    const doc = await createResource<BusinessProfileAttributes>('dashboard/profiles', data)
    return extractAttributes(doc.data)
  },

  async update(profileKey: string, data: { webhook_url?: string }) {
    const doc = await updateResource<BusinessProfileAttributes>(
      `dashboard/profiles/${profileKey}`,
      data,
    )
    return extractAttributes(doc.data)
  },

  async delete(profileKey: string) {
    await deleteResource(`dashboard/profiles/${profileKey}`)
  },
} as const
