import { useQuery } from '@tanstack/react-query'
import {
  dashboardPayments,
  type PaymentListParams,
} from '@/api/endpoints/dashboard-payments'
import { useContextStore } from '@/stores/context'

const STALE_TIME = 30_000

export function usePaymentsList(params: PaymentListParams = {}) {
  const merchantKey = useContextStore((s) => s.currentMerchantKey)
  const testMode = useContextStore((s) => s.testMode)

  return useQuery({
    queryKey: ['payments', 'list', params, merchantKey, testMode],
    queryFn: () => dashboardPayments.list(params),
    staleTime: STALE_TIME,
    enabled: !!merchantKey,
  })
}
