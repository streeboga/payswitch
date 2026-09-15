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

  /** Connector names the backend lets a merchant add (verified live). */
  async connectable(): Promise<string[]> {
    const res = await api.get('dashboard/connectors/connectable').json<{ data: string[] }>()
    return res.data
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
    const doc = await updateResource<ConnectorAttributes>(
      `dashboard/connectors/${key}`,
      attrs,
    )
    return extractAttributes(doc.data)
  },

  remove(key: string) {
    return deleteResource(`dashboard/connectors/${key}`)
  },

  async testConnection(key: string) {
    const res = await api
      .post(`dashboard/connectors/${key}/test`)
      .json<{ data: { type: string; attributes: { success: boolean; message?: string } } }>()
    return res.data.attributes
  },

  async getCapabilities(key: string) {
    const res = await api
      .get(`dashboard/connectors/${key}/capabilities`)
      .json<{ data: { type: string; id: string; attributes: ConnectorCapabilitiesData } }>()
    return res.data.attributes
  },
} as const

export interface ConnectorCapabilitiesData {
  display_name: Record<string, string>
  logo_path: string
  direct_methods: string[]
  fallback_session_type: string
  amount_unit: string
}
