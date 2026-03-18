import { useQuery } from '@tanstack/react-query'
import {
  dashboardEventLogs,
  type EventLogListParams,
} from '@/api/endpoints/dashboard-event-logs'
import { useContextStore } from '@/stores/context'

const STALE_TIME = 30_000

export function useEventLogsList(params: EventLogListParams = {}) {
  const merchantKey = useContextStore((s) => s.currentMerchantKey)
  const testMode = useContextStore((s) => s.testMode)

  return useQuery({
    queryKey: ['event-logs', 'list', params, merchantKey, testMode],
    queryFn: () => dashboardEventLogs.list(params),
    staleTime: STALE_TIME,
    enabled: !!merchantKey,
  })
}
