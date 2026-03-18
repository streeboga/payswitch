import { useQuery } from '@tanstack/react-query'
import {
  dashboardConnectorHealth,
  type HealthPeriod,
} from '@/api/endpoints/dashboard-connector-health'

const STALE_TIME = 30_000

export function useConnectorHealth(connectorKey: string) {
  return useQuery({
    queryKey: ['connector-health', connectorKey],
    queryFn: () => dashboardConnectorHealth.getHealth(connectorKey),
    staleTime: STALE_TIME,
    enabled: !!connectorKey,
  })
}

export function useConnectorHealthHistory(
  connectorKey: string,
  period: HealthPeriod = '24h',
) {
  return useQuery({
    queryKey: ['connector-health', 'history', connectorKey, period],
    queryFn: () => dashboardConnectorHealth.getHealthHistory(connectorKey, period),
    staleTime: STALE_TIME,
    enabled: !!connectorKey,
  })
}
