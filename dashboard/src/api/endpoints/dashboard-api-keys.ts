import { getCollection, createResource, deleteResource } from '../client'
import type { ApiKeyAttributes, PaginatedResult } from '../types'
import type { ApiKeyType } from '../types'
import { parseCollection } from '../types'

// ─── Create Attrs ───────────────────────────────────────────

export interface ApiKeyCreateAttrs {
  name: string
  type: ApiKeyType
}

// ─── API Functions ──────────────────────────────────────────

export const dashboardApiKeys = {
  async list(): Promise<PaginatedResult<ApiKeyAttributes>> {
    const doc = await getCollection<ApiKeyAttributes>('dashboard/api-keys')
    return parseCollection(doc)
  },

  create(attrs: ApiKeyCreateAttrs) {
    return createResource<ApiKeyAttributes>('dashboard/api-keys', attrs)
  },

  revoke(keyId: string) {
    return deleteResource(`dashboard/api-keys/${keyId}`)
  },
} as const
