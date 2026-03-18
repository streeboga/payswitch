import { api, getCollection } from '../client'
import type { PaginatedResult } from '../types'
import { parseCollection, buildJsonApiParams, extractAttributes } from '../types'

// ─── Types ──────────────────────────────────────────────────

export type DisputeType = 'chargeback' | 'inquiry' | 'pre_arbitration'
export type DisputeStatus =
  | 'open'
  | 'under_review'
  | 'won'
  | 'lost'
  | 'evidence_submitted'
  | 'expired'

export interface DisputeAttributes {
  payment_id: string
  amount: number
  currency: string
  type: DisputeType
  status: DisputeStatus
  reason: string
  deadline: string
  connector: string | null
  evidence_text: string | null
  evidence_files: string[]
  timeline: DisputeTimelineEvent[]
  created_at: string
}

export interface DisputeTimelineEvent {
  event: string
  timestamp: string
}

// ─── Filter Params ──────────────────────────────────────────

export interface DisputeListParams {
  type?: string
  status?: string
  from?: string
  to?: string
  search?: string
  sort?: string
  direction?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

// ─── API Functions ──────────────────────────────────────────

export const dashboardDisputes = {
  async list(
    params: DisputeListParams = {},
  ): Promise<PaginatedResult<DisputeAttributes>> {
    const doc = await getCollection<DisputeAttributes>('dashboard/disputes', {
      searchParams: buildJsonApiParams(params),
    })
    return parseCollection(doc)
  },

  async get(key: string) {
    const doc = await api
      .get(`dashboard/disputes/${key}`)
      .json<{ data: { type: string; id: string; attributes: DisputeAttributes } }>()
    return extractAttributes(doc.data)
  },

  async uploadEvidence(key: string, data: { text?: string; files?: File[] }) {
    const formData = new FormData()
    if (data.text) formData.append('evidence_text', data.text)
    if (data.files) {
      for (const file of data.files) {
        formData.append('files[]', file)
      }
    }
    const doc = await api
      .post(`dashboard/disputes/${key}/evidence`, {
        body: formData,
        headers: { 'Content-Type': undefined as unknown as string },
      })
      .json<{ data: { type: string; id: string; attributes: DisputeAttributes } }>()
    return extractAttributes(doc.data)
  },

  async update(key: string, data: Partial<DisputeAttributes>) {
    const doc = await api
      .patch(`dashboard/disputes/${key}`, {
        json: data,
      })
      .json<{ data: { type: string; id: string; attributes: DisputeAttributes } }>()
    return extractAttributes(doc.data)
  },
} as const
