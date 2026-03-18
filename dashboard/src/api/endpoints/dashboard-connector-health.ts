import { api, getResource } from '../client'
import { extractAttributes } from '../types'

// ─── Types ──────────────────────────────────────────────────

export type HealthStatus = 'healthy' | 'degraded' | 'down'

export interface ConnectorHealthAttributes {
  connector_key: string
  connector_name: string
  status: HealthStatus
  uptime_percent: number
  avg_latency_ms: number
  error_rate_percent: number
  success_rate_percent: number
  last_checked_at: string
  recent_errors: ConnectorHealthError[]
  operations: ConnectorOperationStats[]
  comparison: ConnectorComparison[]
}

export interface ConnectorHealthError {
  timestamp: string
  operation: string
  error_code: string
  error_message: string
}

export interface ConnectorOperationStats {
  operation: string
  success_rate: number
  total_count: number
  error_count: number
}

export interface ConnectorComparison {
  connector_key: string
  connector_name: string
  success_rate: number
  avg_latency_ms: number
}

export type HealthPeriod = '24h' | '7d' | '30d'

export interface ConnectorHealthHistoryPoint {
  timestamp: string
  avg_latency_ms: number
  error_rate_percent: number
  success_rate_percent: number
  request_count: number
}

export interface ConnectorHealthHistoryResponse {
  data: ConnectorHealthHistoryPoint[]
  period: HealthPeriod
}

// ─── API Functions ──────────────────────────────────────────

export const dashboardConnectorHealth = {
  async getHealth(connectorKey: string) {
    const doc = await getResource<ConnectorHealthAttributes>(
      `dashboard/connectors/${connectorKey}/health`,
    )
    return extractAttributes(doc.data)
  },

  getHealthHistory(connectorKey: string, period: HealthPeriod = '24h') {
    return api
      .get(`dashboard/connectors/${connectorKey}/health/history`, {
        searchParams: { period },
      })
      .json<ConnectorHealthHistoryResponse>()
  },
} as const
