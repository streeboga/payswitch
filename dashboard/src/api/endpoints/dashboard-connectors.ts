import {
  api,
  getCollection,
  createResource,
  updateResource,
  deleteResource,
} from '../client'
import type { ConnectorAttributes, PaginatedResult } from '../types'
import { parseCollection, extractAttributes } from '../types'

// ─── Create / Update Attrs ──────────────────────────────────

export interface ConnectorCreateAttrs {
  connector_name: string
  connector_type: string
  connector_account_details: Record<string, unknown>
  profile_id?: string
  payment_methods_enabled?: string[]
  test_mode?: boolean
}

export interface ConnectorUpdateAttrs {
  connector_account_details?: Record<string, unknown>
  payment_methods_enabled?: string[]
  disabled?: boolean
}

// ─── API Functions ──────────────────────────────────────────

export const dashboardConnectors = {
  async list(): Promise<PaginatedResult<ConnectorAttributes>> {
    const doc = await getCollection<ConnectorAttributes>('dashboard/connectors')
    return parseCollection(doc)
  },

  async get(key: string) {
    const doc = await api
      .get(`dashboard/connectors/${key}`)
      .json<{ data: { type: string; id: string; attributes: ConnectorAttributes } }>()
    return extractAttributes(doc.data)
  },

  async create(attrs: ConnectorCreateAttrs) {
    const doc = await createResource<ConnectorAttributes>('dashboard/connectors', attrs)
    return extractAttributes(doc.data)
  },

  async update(key: string, attrs: ConnectorUpdateAttrs) {
    const doc = await updateResource<ConnectorAttributes>(`dashboard/connectors/${key}`, attrs)
    return extractAttributes(doc.data)
  },

  remove(key: string) {
    return deleteResource(`dashboard/connectors/${key}`)
  },

  testConnection(key: string) {
    return api
      .post(`dashboard/connectors/${key}/test`)
      .json<{ success: boolean; message?: string }>()
  },
} as const
