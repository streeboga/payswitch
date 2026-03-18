import { api } from '../client'
import type { JsonApiResource } from '../types/json-api'

// ─── Types ──────────────────────────────────────────────────

export type AnalyticsPeriod = 'today' | '7d' | '30d' | '90d'

export interface OverviewData {
  total_volume: number
  transaction_count: number
  conversion_rate: number
  refund_rate: number
  avg_ticket: number
  dispute_count: number
}

export interface ChartPoint {
  date: string
  payments: number
  volume: number
}

export interface ChartsData {
  data: ChartPoint[]
}

export interface FunnelData {
  created: number
  confirmed: number
  succeeded: number
}

export interface PaymentMethodStat {
  method: string
  count: number
  volume: number
}

export interface PaymentMethodsData {
  data: PaymentMethodStat[]
}

export interface FailureReasonStat {
  reason: string
  count: number
}

export interface FailureReasonsData {
  data: FailureReasonStat[]
}

// ─── Backend response types ─────────────────────────────────

interface BackendOverview {
  total_count: number
  successful_count: number
  failed_count: number
  total_amount: number
  net_amount: number
  success_rate: number
  refund_count: number
  refund_amount: number
}

interface BackendFunnel {
  created: number
  confirmed: number
  authorized: number
  captured: number
}

// ─── API Functions ──────────────────────────────────────────

const BASE = 'dashboard/analytics'

function periodParams(period: AnalyticsPeriod) {
  return { searchParams: { period } }
}

export const analyticsApi = {
  async getOverview(period: AnalyticsPeriod): Promise<OverviewData> {
    const res = await api
      .get(`${BASE}/overview`, periodParams(period))
      .json<{ data: JsonApiResource<BackendOverview> }>()
    const a = res.data.attributes
    return {
      total_volume: a.total_amount,
      transaction_count: a.total_count,
      conversion_rate: a.success_rate,
      refund_rate: a.total_count > 0 ? (a.refund_count / a.total_count) * 100 : 0,
      avg_ticket: a.total_count > 0 ? a.total_amount / a.total_count : 0,
      dispute_count: 0,
    }
  },

  async getCharts(period: AnalyticsPeriod): Promise<ChartsData> {
    const res = await api
      .get(`${BASE}/charts`, periodParams(period))
      .json<{ data: JsonApiResource<ChartPoint>[] }>()
    return {
      data: res.data.map((r) => r.attributes),
    }
  },

  async getFunnel(period: AnalyticsPeriod): Promise<FunnelData> {
    const res = await api
      .get(`${BASE}/funnel`, periodParams(period))
      .json<{ data: JsonApiResource<BackendFunnel> }>()
    const a = res.data.attributes
    return {
      created: a.created,
      confirmed: a.confirmed,
      succeeded: a.captured,
    }
  },

  async getPaymentMethods(period: AnalyticsPeriod): Promise<PaymentMethodsData> {
    const res = await api
      .get(`${BASE}/payment-methods`, periodParams(period))
      .json<{ data: JsonApiResource<PaymentMethodStat>[] }>()
    return {
      data: res.data.map((r) => r.attributes),
    }
  },

  async getFailureReasons(period: AnalyticsPeriod): Promise<FailureReasonsData> {
    const res = await api
      .get(`${BASE}/failure-reasons`, periodParams(period))
      .json<{ data: JsonApiResource<FailureReasonStat>[] }>()
    return {
      data: res.data.map((r) => r.attributes),
    }
  },
} as const
