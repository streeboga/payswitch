import { useQuery } from '@tanstack/react-query'
import {
  dashboardRefunds,
  type RefundListParams,
} from '@/api/endpoints/dashboard-refunds'
import { useContextStore } from '@/stores/context'

const STALE_TIME = 30_000

export function useRefundsList(params: RefundListParams = {}) {
  const merchantKey = useContextStore((s) => s.currentMerchantKey)
  const testMode = useContextStore((s) => s.testMode)

  return useQuery({
    queryKey: ['refunds', 'list', params, merchantKey, testMode],
    queryFn: () => dashboardRefunds.list(params),
    staleTime: STALE_TIME,
    enabled: !!merchantKey,
  })
}
